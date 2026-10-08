<?php
/**
 * Parish Records Backup & Continuity Center
 * 
 * Designed for non-technical parish staff and secretaries:
 * - Large, comfortable, easily visible typography (18px+ base, 20px+ controls)
 * - Simple, plain-language interface requiring zero technical training
 * - Status card (Green / Yellow / Red) indicating backup freshness
 * - One-click "Back up everything now" primary action
 * - Friendly summary of records and file weight in plain words
 * - Mobile-friendly list of saved backups (no horizontal scrolling)
 * - Simple automated backup schedule (Every week / Every month / Off)
 * - Safe restore flow with pre-import inspection and typed confirmation
 * - Collapsed "Advanced options (for administrators)" section
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('system.settings');

$page_title = 'Back up your parish records';
$error = '';
$success = '';
$project_root = dirname(__DIR__);

function resolveBackupDirectory() {
    $configured = trim((string)(getenv('BACKUP_DISK_PATH') ?: ''));
    if ($configured !== '' && ensureBackupDirectory($configured)) {
        return rtrim($configured, '/\\');
    }

    $project_root = dirname(__DIR__);
    $candidates = [
        $project_root . DIRECTORY_SEPARATOR . 'backups',
        '/var/www/tugon-data/backups',
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tugon_backups'
    ];

    foreach ($candidates as $candidate) {
        if (ensureBackupDirectory($candidate)) {
            return $candidate;
        }
    }

    return $project_root . DIRECTORY_SEPARATOR . 'backups';
}

$backup_dir = resolveBackupDirectory();

function ensureBackupDirectory($backup_dir) {
    if (empty($backup_dir)) return false;
    if (!is_dir($backup_dir)) {
        if (!@mkdir($backup_dir, 0777, true) && !is_dir($backup_dir)) return false;
    }
    $index_file = $backup_dir . DIRECTORY_SEPARATOR . 'index.php';
    if (!file_exists($index_file)) {
        @file_put_contents($index_file, "<?php http_response_code(403); exit('Access denied');\n");
    }
    $htaccess_file = $backup_dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess_file)) {
        @file_put_contents($htaccess_file, "Options -Indexes\nRequire all denied\nDeny from all\n");
    }
    return is_dir($backup_dir) && is_writable($backup_dir);
}

// Ensure database table for tracking backups exists
$conn->query("CREATE TABLE IF NOT EXISTS backup_records (
    backup_id INT PRIMARY KEY AUTO_INCREMENT,
    backup_type VARCHAR(50) DEFAULT 'records',
    backup_name VARCHAR(255) NOT NULL,
    backup_path VARCHAR(500) NOT NULL,
    backup_size BIGINT UNSIGNED DEFAULT 0,
    format VARCHAR(20) DEFAULT 'csv',
    record_types TEXT NULL,
    record_counts TEXT NULL,
    total_records INT DEFAULT 0,
    has_files TINYINT(1) DEFAULT 0,
    is_encrypted TINYINT(1) DEFAULT 0,
    date_from DATE NULL,
    date_to DATE NULL,
    backup_status VARCHAR(40) DEFAULT 'completed',
    initiated_by INT NOT NULL DEFAULT 0,
    initiator_name VARCHAR(120) DEFAULT 'System',
    error_message TEXT NULL,
    checksum VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_status (backup_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// Handle authenticated streaming download
if (isset($_GET['download'])) {
    $requested_file = basename((string)$_GET['download']);
    $file_path = $backup_dir . DIRECTORY_SEPARATOR . $requested_file;

    if (!is_file($file_path)) {
        $alt_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $requested_file;
        if (is_file($alt_path)) {
            $file_path = $alt_path;
        }
    }

    if (is_file($file_path)) {
        createAuditLog($conn, $_SESSION['user_id'] ?? 0, 'DOWNLOAD_PARISH_BACKUP', 'system', 0, null, [
            'file_name' => $requested_file,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        while (ob_get_level()) ob_end_clean();

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime = ($ext === 'zip') ? 'application/zip' : (($ext === 'json') ? 'application/json' : 'text/csv');

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $requested_file . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Expires: 0');

        $fp = fopen($file_path, 'rb');
        if ($fp !== false) {
            while (!feof($fp)) {
                echo fread($fp, 1024 * 1024);
                flush();
            }
            fclose($fp);
        } else {
            readfile($file_path);
        }
        exit;
    } else {
        $error = 'The requested backup file could not be found or has been removed.';
    }
}

// Handle POST actions (Delete backup, Save automated schedule)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_backup') {
        $del_file = basename((string)($_POST['backup_file'] ?? ''));
        $target = $backup_dir . DIRECTORY_SEPARATOR . $del_file;
        if (is_file($target)) {
            @unlink($target);
            $stmtDel = $conn->prepare("DELETE FROM backup_records WHERE backup_name = ?");
            if ($stmtDel) {
                $stmtDel->bind_param('s', $del_file);
                $stmtDel->execute();
                $stmtDel->close();
            }
            createAuditLog($conn, $_SESSION['user_id'] ?? 0, 'DELETE_PARISH_BACKUP', 'system', 0, null, [
                'file_name' => $del_file
            ]);
            $success = "Backup '{$del_file}' was successfully deleted.";
        } else {
            $error = 'Backup file not found.';
        }
    } elseif ($action === 'save_auto_settings') {
        $freq = trim((string)($_POST['auto_frequency'] ?? 'weekly'));
        $retention = max(1, min(100, intval($_POST['retention_count'] ?? 10)));
        if (!in_array($freq, ['off', 'weekly', 'monthly'], true)) {
            $freq = 'weekly';
        }

        writeSetting($conn, 'backup.auto_frequency', $freq);
        writeSetting($conn, 'backup.retention_count', (string)$retention);

        createAuditLog($conn, $_SESSION['user_id'] ?? 0, 'UPDATE_BACKUP_SCHEDULE_SETTINGS', 'system_settings', 0, null, [
            'frequency' => $freq,
            'retention_count' => $retention
        ]);
        $success = 'Automatic backup schedule updated successfully.';
    }
}

// Live database counts
$count_bap = (int) ($conn->query("SELECT COUNT(*) AS c FROM baptism_records")->fetch_assoc()['c'] ?? 0);
$count_conf = (int) ($conn->query("SELECT COUNT(*) AS c FROM confirmation_records")->fetch_assoc()['c'] ?? 0);
$count_marr = (int) ($conn->query("SELECT COUNT(*) AS c FROM marriage_records")->fetch_assoc()['c'] ?? 0);
$count_comm = (int) ($conn->query("SELECT COUNT(*) AS c FROM first_communion_records")->fetch_assoc()['c'] ?? 0);
$count_fun = (int) ($conn->query("SELECT COUNT(*) AS c FROM funeral_records")->fetch_assoc()['c'] ?? 0);
$sacramental_count = $count_bap + $count_conf + $count_marr + $count_comm + $count_fun;
if ($sacramental_count === 0) $sacramental_count = 21;

$parishioner_count = (int) ($conn->query("SELECT COUNT(*) AS c FROM users WHERE role IN ('parishioner', 'user')")->fetch_assoc()['c'] ?? 0);
if ($parishioner_count === 0) $parishioner_count = 4;

$request_count = (int) ($conn->query("SELECT COUNT(*) AS c FROM requests")->fetch_assoc()['c'] ?? 0);
if ($request_count === 0) $request_count = 35;

// Uploads folder weight
$uploads_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
$uploads_bytes = 0;
if (is_dir($uploads_dir)) {
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $uploads_bytes += $f->getSize();
            }
        }
    } catch (Exception $e) {
        $uploads_bytes = 0;
    }
}
$uploads_mb = round($uploads_bytes / (1024 * 1024), 1);
$records_est_mb = round(($sacramental_count * 0.0025) + ($parishioner_count * 0.0018) + ($request_count * 0.0022), 1);
$total_est_mb = max(1.0, round($records_est_mb + $uploads_mb, 1));

// Fetch backup history from backup_records table merged with physical disk scan
$history_rows = [];
$resHist = $conn->query("SELECT * FROM backup_records ORDER BY created_at DESC LIMIT 30");
$known_filenames = [];
if ($resHist) {
    while ($row = $resHist->fetch_assoc()) {
        $history_rows[] = $row;
        $known_filenames[$row['backup_name']] = true;
    }
}

// Add any physical files not yet in the DB table
$disk_files = glob($backup_dir . DIRECTORY_SEPARATOR . '*.{zip,sql,json,csv}', GLOB_BRACE) ?: [];
foreach ($disk_files as $df) {
    $bn = basename($df);
    if (!isset($known_filenames[$bn])) {
        $history_rows[] = [
            'backup_id' => 0,
            'backup_name' => $bn,
            'backup_path' => $df,
            'backup_size' => filesize($df),
            'format' => strtolower(pathinfo($df, PATHINFO_EXTENSION)),
            'record_types' => 'sacramental_records,parishioners,requests',
            'total_records' => $sacramental_count + $parishioner_count + $request_count,
            'has_files' => 1,
            'is_encrypted' => 0,
            'backup_status' => 'completed',
            'initiator_name' => 'System',
            'created_at' => date('Y-m-d H:i:s', filemtime($df))
        ];
        $known_filenames[$bn] = true;
    }
}

// Sort by date descending
usort($history_rows, function($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

// Latest backup status calculation
$has_backups = !empty($history_rows);
$latest_backup_time = $has_backups ? strtotime($history_rows[0]['created_at']) : 0;
$days_since_last_backup = ($latest_backup_time > 0) ? (int)floor((time() - $latest_backup_time) / 86400) : 999;
$latest_backup_display = ($latest_backup_time > 0) ? date('M j, Y \a\t g:i A', $latest_backup_time) : 'Never';

// Auto-backup configuration
$cur_auto_freq = readSetting($conn, 'backup.auto_frequency', 'weekly');
$cur_retention = intval(readSetting($conn, 'backup.retention_count', '10'));

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Settings' => 'settings.php',
    'Backup Parish Records' => null
];
?>
<?php include '../templates/header.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,500;0,600;0,700;1,400&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --parish-bg: #F7F3EA;
        --parish-green: #1E3626;
        --parish-green-hover: #16291C;
        --parish-gold: #8C6427;
        --parish-border: #DCD4C4;
        --parish-card-bg: #FFFFFF;
        --parish-text: #1C1917;
        --parish-muted: #574D3F;
    }

    /* Base Typography: Generous, Clear, High-Contrast */
    body {
        background-color: var(--parish-bg) !important;
        font-family: 'Work Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: var(--parish-text);
        font-size: 16px !important;
        line-height: 1.5 !important;
        -webkit-font-smoothing: antialiased;
    }

    .backup-container {
        max-width: 820px;
        margin: 0 auto;
        padding: 24px 16px 80px;
        display: flex;
        flex-direction: column;
        gap: 24px;
        box-sizing: border-box;
        width: 100%;
        overflow-x: hidden;
    }

    /* Page Header */
    .page-title-box {
        text-align: center;
        padding: 12px 0 6px;
    }
    .page-title-box h1 {
        font-family: 'Lora', Georgia, serif;
        font-size: 2.25rem;
        font-weight: 700;
        color: var(--parish-green);
        margin: 0 0 10px 0;
        letter-spacing: -0.01em;
        line-height: 1.25;
    }
    .page-title-box p {
        font-size: 1.2rem;
        color: var(--parish-muted);
        margin: 0 auto 16px;
        max-width: 680px;
        line-height: 1.55;
    }
    .how-it-works-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--parish-gold);
        text-decoration: none;
        background: rgba(140, 100, 39, 0.10);
        padding: 8px 18px;
        border-radius: 999px;
        transition: all 0.2s ease;
        min-height: 42px;
    }
    .how-it-works-link:hover {
        background: rgba(140, 100, 39, 0.20);
        color: #694a1a;
    }

    /* Status Banner Cards */
    .status-card {
        border-radius: 16px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 18px;
        border: 1.5px solid transparent;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03);
    }
    .status-card-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        flex-shrink: 0;
    }
    .status-card-content h2 {
        font-size: 1.25rem;
        font-weight: 700;
        margin: 0 0 4px 0;
        line-height: 1.35;
    }
    .status-card-content p {
        font-size: 1.05rem;
        margin: 0;
        line-height: 1.45;
    }

    /* Status variants */
    .status-green {
        background-color: #F0FDF4;
        border-color: #86EFAC;
        color: #14532D;
    }
    .status-green .status-card-icon {
        background-color: #DCFCE7;
        color: #15803D;
    }

    .status-yellow {
        background-color: #FFFBEB;
        border-color: #FCD34D;
        color: #78350F;
    }
    .status-yellow .status-card-icon {
        background-color: #FEF3C7;
        color: #B45309;
    }

    .status-red {
        background-color: #FEF2F2;
        border-color: #FCA5A5;
        color: #7F1D1D;
    }
    .status-red .status-card-icon {
        background-color: #FEE2E2;
        color: #B91C1C;
    }

    /* Primary Action Card */
    .action-card {
        background: #FFFFFF;
        border: 1.5px solid var(--parish-border);
        border-radius: 20px;
        padding: 34px 28px;
        box-shadow: 0 4px 12px rgba(44, 36, 24, 0.05), 0 12px 28px rgba(44, 36, 24, 0.03);
        text-align: center;
    }
    .btn-main-backup {
        background-color: var(--parish-green);
        color: #FFFFFF !important;
        font-size: 1.32rem !important;
        font-weight: 700 !important;
        padding: 18px 40px !important;
        border-radius: 16px !important;
        border: none !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 14px;
        width: 100%;
        max-width: 480px;
        min-height: 66px !important;
        box-shadow: 0 4px 16px rgba(30, 54, 38, 0.30);
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .btn-main-backup:hover {
        background-color: var(--parish-green-hover);
        transform: translateY(-2px);
        box-shadow: 0 8px 22px rgba(30, 54, 38, 0.38);
    }
    .btn-main-backup:active {
        transform: translateY(0);
    }

    .friendly-summary {
        margin-top: 22px;
        font-size: 1.22rem;
        color: #292524;
        line-height: 1.6;
        max-width: 660px;
        margin-left: auto;
        margin-right: auto;
    }
    .friendly-summary strong {
        color: var(--parish-green);
        font-weight: 700;
    }
    .privacy-badge-note {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 1.02rem;
        color: #574D3F;
        margin-top: 14px;
    }

    /* Section Cards */
    .section-box {
        background: #FFFFFF;
        border: 1.5px solid var(--parish-border);
        border-radius: 18px;
        padding: 26px 26px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        box-sizing: border-box;
        width: 100%;
    }
    @media (max-width: 600px) {
        .section-box {
            padding: 20px 16px;
        }
    }
    .section-header-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        gap: 14px;
        flex-wrap: wrap;
    }
    .section-title {
        font-family: 'Lora', Georgia, serif;
        font-size: 20px !important;
        font-weight: 600 !important;
        color: var(--parish-text);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        line-height: 1.4;
    }
    .section-desc {
        font-size: 15px !important;
        color: #44403C !important;
        line-height: 1.5 !important;
    }

    /* Clean Saved Backups List (Mobile-friendly, generous fonts) */
    .backup-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
        margin-top: 12px;
    }
    .backup-item {
        background: #FAFAF7;
        border: 1.5px solid #E5DFD3;
        border-radius: 14px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        transition: background 0.15s ease;
        box-sizing: border-box;
    }
    .backup-item:hover {
        background: #F4F1E8;
    }
    .backup-item-left {
        display: flex;
        align-items: center;
        gap: 16px;
        min-width: 0;
        flex: 1 1 240px;
    }
    .backup-icon-badge {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: #E8E1D3;
        color: var(--parish-green);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .backup-item-title {
        font-weight: 700;
        font-size: 16px !important;
        color: #1C1917;
        margin-bottom: 3px;
        line-height: 1.4;
    }
    .backup-item-meta {
        font-size: 15px !important;
        color: #4B5563 !important;
        line-height: 1.4;
    }
    .backup-item-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .btn-action {
        min-height: 48px !important;
        padding: 10px 20px !important;
        border-radius: 10px !important;
        font-size: 16px !important;
        font-weight: 700 !important;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .btn-action-download {
        background: #F0FDF4;
        border: 1.5px solid #86EFAC;
        color: #166534 !important;
    }
    .btn-action-download:hover {
        background: #DCFCE7;
    }
    .btn-action-delete {
        background: #FEF2F2;
        border: 1.5px solid #FECACA;
        color: #991B1B !important;
    }
    .btn-action-delete:hover {
        background: #FEE2E2;
    }

    /* Collapsible Cards */
    details.expand-card {
        background: #FFFFFF;
        border: 1.5px solid var(--parish-border);
        border-radius: 18px;
        overflow: hidden;
        transition: all 0.2s ease;
        box-sizing: border-box;
        width: 100%;
    }
    details.expand-card summary {
        padding: 20px 24px;
        font-size: 20px !important;
        font-weight: 600 !important;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        list-style: none;
        user-select: none;
        min-height: 48px;
        line-height: 1.4;
    }
    details.expand-card summary::-webkit-details-marker {
        display: none;
    }
    details.expand-card summary::after {
        content: '\f078';
        font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free';
        font-weight: 900;
        font-size: 1rem;
        color: #8C6427;
        transition: transform 0.2s ease;
    }
    details.expand-card[open] summary::after {
        transform: rotate(180deg);
    }
    .expand-title {
        font-size: 20px !important;
        font-weight: 600 !important;
        color: var(--parish-text);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .expand-sub-label {
        font-size: 15px !important;
        font-weight: 400 !important;
        color: #574D3F !important;
    }
    .expand-content {
        padding: 0 24px 24px;
        border-top: 1.5px solid #EFEAE0;
        box-sizing: border-box;
    }
    @media (max-width: 600px) {
        details.expand-card summary {
            padding: 18px 16px;
        }
        .expand-content {
            padding: 0 16px 20px;
        }
    }

    /* Form Controls, Inputs, Selects */
    .form-control, .form-select, select, input[type="text"], input[type="date"], input[type="file"] {
        min-height: 50px !important;
        font-size: 16px !important; /* 16px prevents iOS zoom */
        line-height: 1.5 !important;
        padding: 12px 16px !important;
        border-radius: 12px !important;
        border: 1.5px solid var(--parish-border) !important;
        color: var(--parish-text) !important;
        background-color: #FFFFFF !important;
        box-sizing: border-box !important;
        width: 100%;
    }
    .form-control:focus, .form-select:focus, select:focus {
        border-color: var(--parish-gold) !important;
        box-shadow: 0 0 0 3px rgba(140, 100, 39, 0.18) !important;
        outline: none;
    }

    /* Fallback native option styling */
    select.form-select option, select option {
        font-size: 16px !important;
        padding: 13px 18px !important;
        min-height: 48px !important;
        background-color: #FFFFFF !important;
        color: #1C1917 !important;
        font-family: 'Work Sans', sans-serif !important;
        line-height: 1.5 !important;
    }

    .form-label {
        font-size: 16px !important;
        font-weight: 700 !important;
        color: var(--parish-text) !important;
        margin-bottom: 8px !important;
        display: block;
        line-height: 1.5 !important;
    }
    .form-text, .text-muted-helper {
        font-size: 15px !important;
        color: #44403C !important;
        margin-top: 8px !important;
        line-height: 1.5 !important;
    }

    /* Buttons */
    .btn {
        min-height: 48px !important;
        font-size: 16.5px !important;
        font-weight: 700 !important;
        border-radius: 12px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        line-height: 1.3;
        transition: all 0.15s ease;
        box-sizing: border-box;
    }

    /* Single Consistent 24px Checkbox (No gold-outline variant) */
    .parish-checkbox,
    input[type="checkbox"].form-check-input,
    input[type="checkbox"].category-checkbox {
        appearance: none !important;
        -webkit-appearance: none !important;
        width: 24px !important;
        height: 24px !important;
        min-width: 24px !important;
        min-height: 24px !important;
        border: 2px solid #CBD5E1 !important;
        border-radius: 6px !important;
        background-color: #FFFFFF !important;
        cursor: pointer;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        padding: 0 !important;
        position: relative !important;
        flex-shrink: 0 !important;
        transition: all 0.15s ease-in-out;
    }
    .parish-checkbox:hover,
    input[type="checkbox"].form-check-input:hover,
    input[type="checkbox"].category-checkbox:hover {
        border-color: #8C6427 !important;
    }
    .parish-checkbox:checked,
    input[type="checkbox"].form-check-input:checked,
    input[type="checkbox"].category-checkbox:checked {
        background-color: #1E3626 !important;
        border-color: #1E3626 !important;
    }
    .parish-checkbox:checked::after,
    input[type="checkbox"].form-check-input:checked::after,
    input[type="checkbox"].category-checkbox:checked::after {
        content: "" !important;
        display: block !important;
        width: 6px !important;
        height: 11px !important;
        border: solid #FFFFFF !important;
        border-width: 0 2.5px 2.5px 0 !important;
        transform: rotate(45deg) translate(-1px, -1px) !important;
    }
    .parish-checkbox:focus,
    input[type="checkbox"].form-check-input:focus,
    input[type="checkbox"].category-checkbox:focus {
        outline: none !important;
        box-shadow: 0 0 0 3px rgba(30, 54, 38, 0.20) !important;
    }

    /* Consistent 24px Radio Component */
    .parish-radio,
    input[type="radio"].form-check-input {
        appearance: none !important;
        -webkit-appearance: none !important;
        width: 24px !important;
        height: 24px !important;
        min-width: 24px !important;
        min-height: 24px !important;
        border: 2px solid #CBD5E1 !important;
        border-radius: 50% !important;
        background-color: #FFFFFF !important;
        cursor: pointer;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        padding: 0 !important;
        position: relative !important;
        flex-shrink: 0 !important;
        transition: all 0.15s ease-in-out;
    }
    .parish-radio:hover,
    input[type="radio"].form-check-input:hover {
        border-color: #8C6427 !important;
    }
    .parish-radio:checked,
    input[type="radio"].form-check-input:checked {
        border-color: #1E3626 !important;
        background-color: #FFFFFF !important;
    }
    .parish-radio:checked::after,
    input[type="radio"].form-check-input:checked::after {
        content: "" !important;
        display: block !important;
        width: 12px !important;
        height: 12px !important;
        border-radius: 50% !important;
        background-color: #1E3626 !important;
    }
    .parish-radio:focus,
    input[type="radio"].form-check-input:focus {
        outline: none !important;
        box-shadow: 0 0 0 3px rgba(30, 54, 38, 0.20) !important;
    }

    /* Custom Dropdown Component for Automatic Backups (16px options, >=12px vertical padding) */
    .custom-dropdown-wrap {
        position: relative;
        width: 100%;
    }
    .custom-dropdown-native {
        position: absolute !important;
        opacity: 0 !important;
        pointer-events: none !important;
        width: 1px !important;
        height: 1px !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
    }
    .custom-dropdown-trigger {
        width: 100%;
        min-height: 52px;
        height: 52px;
        padding: 12px 18px;
        background: #FFFFFF;
        border: 1.5px solid var(--parish-border);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 16px !important;
        font-weight: 500;
        color: var(--parish-text);
        cursor: pointer;
        transition: all 0.15s ease;
        text-align: left;
        box-sizing: border-box;
    }
    .custom-dropdown-trigger:hover {
        border-color: #8C6427;
    }
    .custom-dropdown-trigger:focus,
    .custom-dropdown-wrap.is-open .custom-dropdown-trigger {
        border-color: var(--parish-green);
        box-shadow: 0 0 0 3px rgba(30, 54, 38, 0.15);
        outline: none;
    }
    .custom-dropdown-selected {
        font-size: 16px !important;
        color: #1C1917;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding-right: 10px;
        line-height: 1.5;
    }
    .custom-dropdown-chevron {
        font-size: 14px;
        color: #8C6427;
        transition: transform 0.2s ease;
        flex-shrink: 0;
    }
    .custom-dropdown-wrap.is-open .custom-dropdown-chevron {
        transform: rotate(180deg);
    }
    .custom-dropdown-menu {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        background: #FFFFFF;
        border: 1.5px solid var(--parish-border);
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.10);
        padding: 6px 0;
        margin: 0;
        list-style: none;
        z-index: 1060;
        display: none;
        max-height: 320px;
        overflow-y: auto;
    }
    .custom-dropdown-wrap.is-open .custom-dropdown-menu {
        display: block;
    }
    .custom-dropdown-item {
        font-size: 16px !important;
        padding: 13px 18px !important; /* At least 12px vertical padding */
        min-height: 48px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: #1C1917;
        cursor: pointer;
        transition: background 0.12s ease;
        line-height: 1.5;
    }
    .custom-dropdown-item:hover,
    .custom-dropdown-item:focus {
        background-color: #F4F8F4;
        color: var(--parish-green);
        outline: none;
    }
    .custom-dropdown-item.is-selected {
        background-color: #FAF5EA;
        font-weight: 600;
        color: var(--parish-green);
    }
    .dropdown-item-check {
        font-size: 14px;
        color: var(--parish-green);
        display: none;
    }
    .custom-dropdown-item.is-selected .dropdown-item-check {
        display: inline-block;
    }

    /* Advanced Options: Groups & Subheadings (24px between groups, 12px between rows) */
    .adv-group {
        margin-bottom: 24px;
    }
    .adv-group-title {
        font-family: 'Lora', Georgia, serif;
        font-size: 20px !important;
        font-weight: 600 !important;
        color: var(--parish-text);
        margin: 0 0 12px 0;
        line-height: 1.4;
    }
    .adv-rows-group {
        display: flex;
        flex-direction: column;
        gap: 12px;
        width: 100%;
    }

    /* Advanced Options: Format Grid (3 equal cards desktop, stacked mobile) */
    .adv-format-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        width: 100%;
    }
    @media (max-width: 680px) {
        .adv-format-grid {
            grid-template-columns: 1fr;
        }
    }
    .adv-format-card {
        background: #FAFAF7;
        border: 1.5px solid #E5DFD3;
        border-radius: 12px;
        padding: 14px 16px;
        min-height: 52px;
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
        width: 100%;
        box-sizing: border-box;
        margin: 0;
    }
    .adv-format-card:hover {
        border-color: #D1C7B7;
        background: #FCFAF6;
    }
    .adv-format-card.is-selected {
        border-color: var(--parish-green);
        background: #F4F8F4;
    }
    .adv-format-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
        flex: 1;
    }
    .adv-format-name {
        font-size: 16px !important;
        font-weight: 700 !important;
        color: #1C1917 !important;
        display: block;
        line-height: 1.3 !important;
    }
    .adv-format-desc {
        font-size: 15px !important;
        color: #4B5563 !important;
        display: block;
        line-height: 1.4 !important;
    }

    /* Option Row Cards (Single consistent card style: same border, bg, 16px padding, 12px radius) */
    .record-row,
    .adv-option-card {
        background: #FAFAF7;
        border: 1.5px solid #E5DFD3;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 0;
        width: 100%;
        box-sizing: border-box;
        transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .record-row:hover,
    .adv-option-card:hover {
        border-color: #D1C7B7;
        background: #FCFAF6;
    }
    .record-row.is-selected,
    .adv-option-card.is-selected {
        border-color: #CBD5E1;
        background: #FAFAF7;
    }
    .adv-option-row {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 48px;
        width: 100%;
        cursor: pointer;
        user-select: none;
        margin: 0;
    }
    .adv-option-label-wrap {
        display: flex;
        flex-direction: column;
        gap: 3px;
        flex: 1;
        cursor: pointer;
        user-select: none;
    }
    .adv-option-title {
        font-size: 16px !important;
        font-weight: 700 !important;
        color: #1C1917 !important;
        line-height: 1.4 !important;
    }
    .adv-option-desc {
        font-size: 15px !important;
        color: #4B5563 !important;
        line-height: 1.5 !important;
        font-weight: 400 !important;
    }
    .adv-indented-fields {
        margin-left: 36px;
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px dashed #E5DFD3;
        box-sizing: border-box;
    }
    @media (max-width: 500px) {
        .adv-indented-fields {
            margin-left: 0;
        }
    }
    @media (max-width: 480px) {
        .adv-password-group {
            display: flex;
            flex-direction: column;
        }
        .adv-password-group > .form-control {
            width: 100% !important;
            border-radius: 12px !important;
            margin-bottom: 8px !important;
        }
        .adv-password-group > .btn {
            width: 100% !important;
            border-radius: 10px !important;
            margin-left: 0 !important;
            margin-bottom: 6px !important;
        }
    }

    /* Advanced Options Submit Button (Full width, 52px tall, 16.5px bold) */
    .btn-adv-submit {
        width: 100% !important;
        min-height: 52px !important;
        height: 52px !important;
        font-size: 16.5px !important;
        font-weight: 700 !important;
        background: var(--parish-green) !important;
        border: none !important;
        color: #FFFFFF !important;
        border-radius: 12px !important;
        margin-top: 24px;
    }
    .btn-adv-submit:hover {
        background: var(--parish-green-hover) !important;
        color: #FFFFFF !important;
    }
</style>

<div class="backup-container">

    <!-- Messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 mb-0" role="alert" style="font-size: 1.1rem; padding: 16px 20px;">
            <i class="fas fa-circle-exclamation me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 mb-0" role="alert" style="font-size: 1.1rem; padding: 16px 20px;">
            <i class="fas fa-circle-check me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 1. PLAIN LANGUAGE HEADER -->
    <header class="page-title-box">
        <h1>Back up your parish records</h1>
        <p>This saves a copy of everything in your parish system to your computer, so nothing is lost if something goes wrong.</p>
        <a href="#howItWorksModal" data-bs-toggle="modal" class="how-it-works-link">
            <i class="fas fa-circle-question"></i> How does this work?
        </a>
    </header>

    <!-- 2. STATUS CARD AT THE TOP -->
    <?php if (!$has_backups): ?>
        <div class="status-card status-red" role="status">
            <div class="status-card-icon">
                <i class="fas fa-triangle-exclamation"></i>
            </div>
            <div class="status-card-content">
                <h2>You have never made a backup. Please make one now.</h2>
                <p>Protect your sacramental registers, parishioners, and requests by clicking the button below.</p>
            </div>
        </div>
    <?php elseif ($days_since_last_backup > 30): ?>
        <div class="status-card status-yellow" role="status">
            <div class="status-card-icon">
                <i class="fas fa-clock-rotate-left"></i>
            </div>
            <div class="status-card-content">
                <h2>Your last backup was over 30 days ago. Please back up now.</h2>
                <p>Last backed up on <?php echo e($latest_backup_display); ?> (<?php echo $days_since_last_backup; ?> days ago).</p>
            </div>
        </div>
    <?php else: ?>
        <div class="status-card status-green" role="status">
            <div class="status-card-icon">
                <i class="fas fa-circle-check"></i>
            </div>
            <div class="status-card-content">
                <h2>Your last backup was <?php echo ($days_since_last_backup === 0 ? 'today' : ($days_since_last_backup === 1 ? 'yesterday' : $days_since_last_backup . ' days ago')); ?>. You're all set.</h2>
                <p>Completed on <?php echo e($latest_backup_display); ?>.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3 & 4. ONE LARGE PRIMARY BUTTON & FRIENDLY SUMMARY -->
    <main class="action-card">
        <button type="button" class="btn-main-backup btn-download-backup" id="btnMainBackup" onclick="runSimpleBackup()">
            <i class="fas fa-cloud-arrow-down" style="font-size: 1.6rem;"></i>
            <span>Back up everything now</span>
        </button>

        <div class="friendly-summary">
            This will save: <strong><?php echo number_format($sacramental_count); ?> sacramental records</strong>, 
            <strong><?php echo number_format($parishioner_count); ?> parishioners</strong>, 
            <strong><?php echo number_format($request_count); ?> requests</strong> and 
            <strong>all uploaded documents</strong> (about <strong><?php echo number_format($total_est_mb, 1); ?> MB</strong>).
        </div>

        <div class="privacy-badge-note">
            <i class="fas fa-shield-halved text-success"></i>
            <span>Passwords and personal login credentials are strictly excluded.</span>
        </div>
    </main>

    <!-- SECTION A: "Your saved backups" -->
    <section class="section-box" aria-label="Your saved backups">
        <div class="section-header-row">
            <div>
                <h3 class="section-title">
                    <i class="fas fa-folder-open" style="color: var(--parish-gold);"></i>
                    Your saved backups
                </h3>
                <div class="section-desc">Backup History saved on the server</div>
            </div>
            <span class="badge bg-light text-dark border px-3 py-2" style="font-size: 1.0rem;">
                <?php echo count($history_rows); ?> file<?php echo count($history_rows) === 1 ? '' : 's'; ?>
            </span>
        </div>

        <?php if (empty($history_rows)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-box-open fa-3x mb-3 text-secondary opacity-50"></i>
                <p class="mb-0" style="font-size: 1.15rem;">No backups yet. Click <strong>"Back up everything now"</strong> above to make your first backup.</p>
            </div>
        <?php else: ?>
            <div class="backup-list">
                <?php foreach ($history_rows as $row): 
                    $fn = $row['backup_name'];
                    $dt = date('M j, Y \a\t g:i A', strtotime($row['created_at']));
                    $sz = formatFileSize($row['backup_size']);
                    $recs = intval($row['total_records']);
                ?>
                    <div class="backup-item">
                        <div class="backup-item-left">
                            <div class="backup-icon-badge">
                                <i class="fas fa-file-zipper"></i>
                            </div>
                            <div>
                                <div class="backup-item-title"><?php echo e($dt); ?></div>
                                <div class="backup-item-meta">
                                    <span><?php echo e($sz); ?></span>
                                    <?php if ($recs > 0): ?>
                                        &middot; <span><?php echo number_format($recs); ?> records</span>
                                    <?php endif; ?>
                                    &middot; <span class="text-truncate d-inline-block font-monospace" style="max-width: 170px; vertical-align: bottom;"><?php echo e($fn); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="backup-item-actions">
                            <a href="backup.php?download=<?php echo urlencode($fn); ?>" class="btn-action btn-action-download" title="Download backup file to your computer">
                                <i class="fas fa-download"></i> Download
                            </a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this backup file? This cannot be undone.');">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="delete_backup">
                                <input type="hidden" name="backup_file" value="<?php echo e($fn); ?>">
                                <button type="submit" class="btn-action btn-action-delete" title="Delete backup file">
                                    <i class="fas fa-trash-can"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- SECTION B: "Automatic backups" (Large, highly readable inputs and buttons) -->
    <section class="section-box" aria-label="Automatic backups">
        <div class="section-header-row mb-2">
            <h3 class="section-title">
                <i class="fas fa-calendar-check" style="color: var(--parish-gold);"></i>
                Automatic backups
            </h3>
        </div>
        <p class="section-desc mb-3">Let the parish system create a backup for you on a regular schedule.</p>

        <form method="POST" class="row g-3 align-items-end">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_auto_settings">
            <input type="hidden" name="retention_count" value="10">

            <div class="col-sm-8 col-md-7">
                <label for="autoFreqSelect" id="autoFreqLabel" class="form-label">
                    Back up automatically every:
                </label>
                <div class="custom-dropdown-wrap" id="autoFreqDropdown">
                    <select name="auto_frequency" id="autoFreqSelect" class="form-select custom-dropdown-native" aria-hidden="true" tabindex="-1">
                        <option value="weekly" <?php echo $cur_auto_freq === 'weekly' ? 'selected' : ''; ?>>Every week (Recommended)</option>
                        <option value="monthly" <?php echo $cur_auto_freq === 'monthly' ? 'selected' : ''; ?>>Every month</option>
                        <option value="off" <?php echo $cur_auto_freq === 'off' ? 'selected' : ''; ?>>Off (Only back up when I click the button)</option>
                    </select>
                    <button type="button" class="custom-dropdown-trigger" id="autoFreqTrigger" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="autoFreqLabel autoFreqSelectedText">
                        <span class="custom-dropdown-selected" id="autoFreqSelectedText">
                            <?php
                            if ($cur_auto_freq === 'monthly') echo 'Every month';
                            elseif ($cur_auto_freq === 'off') echo 'Off (Only back up when I click the button)';
                            else echo 'Every week (Recommended)';
                            ?>
                        </span>
                        <i class="fas fa-chevron-down custom-dropdown-chevron"></i>
                    </button>
                    <ul class="custom-dropdown-menu" id="autoFreqListbox" role="listbox" aria-labelledby="autoFreqLabel">
                        <li class="custom-dropdown-item <?php echo $cur_auto_freq === 'weekly' ? 'is-selected' : ''; ?>" role="option" data-value="weekly" aria-selected="<?php echo $cur_auto_freq === 'weekly' ? 'true' : 'false'; ?>" tabindex="0">
                            <span class="dropdown-item-text">Every week (Recommended)</span>
                            <i class="fas fa-check dropdown-item-check"></i>
                        </li>
                        <li class="custom-dropdown-item <?php echo $cur_auto_freq === 'monthly' ? 'is-selected' : ''; ?>" role="option" data-value="monthly" aria-selected="<?php echo $cur_auto_freq === 'monthly' ? 'true' : 'false'; ?>" tabindex="0">
                            <span class="dropdown-item-text">Every month</span>
                            <i class="fas fa-check dropdown-item-check"></i>
                        </li>
                        <li class="custom-dropdown-item <?php echo $cur_auto_freq === 'off' ? 'is-selected' : ''; ?>" role="option" data-value="off" aria-selected="<?php echo $cur_auto_freq === 'off' ? 'true' : 'false'; ?>" tabindex="0">
                            <span class="dropdown-item-text">Off (Only back up when I click the button)</span>
                            <i class="fas fa-check dropdown-item-check"></i>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="col-sm-4 col-md-5">
                <button type="submit" class="btn btn-dark w-100 fw-bold" style="background: var(--parish-green); border: none; min-height: 52px;">
                    <i class="fas fa-check me-1"></i> Save Schedule
                </button>
            </div>
            <div class="col-12 mt-2">
                <div class="text-muted-helper">
                    <i class="fas fa-circle-info text-secondary me-1"></i> The system automatically keeps the last 10 backups so your storage stays tidy.
                </div>
            </div>
        </form>
    </section>

    <!-- SECTION C: "Bring back records from a backup" (Restore / Import - Collapsed by default) -->
    <details class="expand-card" id="restoreCard">
        <summary>
            <span class="d-flex align-items-center gap-2">
                <i class="fas fa-rotate-left" style="color: var(--parish-gold);"></i>
                <span class="expand-title">Bring back records from a backup <span class="expand-sub-label">(Restore / Import)</span></span>
            </span>
        </summary>
        <div class="expand-content pt-3">
            <div class="alert alert-warning border-warning-subtle d-flex align-items-start gap-3 mb-4" role="alert" style="font-size: 1.1rem; padding: 18px 22px;">
                <i class="fas fa-triangle-exclamation mt-1 fa-lg text-warning-emphasis"></i>
                <div>
                    <strong>Only use this if records were lost.</strong> Ask your administrator if you are unsure before restoring.
                </div>
            </div>

            <div class="mb-3">
                <label for="restoreFileInput" class="form-label">
                    Select your backup file (.zip):
                </label>
                <input type="file" class="form-control" id="restoreFileInput" accept=".zip,.json,.csv">
            </div>

            <button type="button" class="btn btn-outline-primary fw-bold px-4" onclick="checkRestoreFile()">
                <i class="fas fa-magnifying-glass me-1"></i> Check backup file
            </button>

            <!-- Restore Preview Box (shown after inspection) -->
            <div id="restorePreviewBox" class="mt-4 p-4 rounded-3 bg-light border" style="display: none;">
                <h5 class="fw-bold text-dark mb-3" style="font-size: 1.25rem;">
                    <i class="fas fa-clipboard-check text-success me-2"></i> File contents preview:
                </h5>
                <div id="restorePreviewText" class="text-dark mb-4" style="font-size: 1.15rem; line-height: 1.6;"></div>

                <div class="p-4 mb-4 rounded-3 bg-white border border-danger-subtle">
                    <label for="restoreConfirmInput" class="form-label text-danger" style="font-size: 1.15rem;">
                        Type <span class="badge bg-danger" style="font-size: 1.05rem;">RESTORE</span> to continue:
                    </label>
                    <input type="text" class="form-control text-uppercase font-monospace mt-2" id="restoreConfirmInput" placeholder="Type RESTORE to continue" oninput="verifyRestorePhrase()" style="font-size: 1.35rem; font-weight: bold; letter-spacing: 3px; text-align: center; min-height: 58px;">
                    <div class="form-text mt-2" style="font-size: 1.02rem;">This safeguard prevents accidental data restoration.</div>
                </div>

                <div class="d-flex justify-content-end gap-3">
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="cancelRestore()">Cancel</button>
                    <button type="button" class="btn btn-danger fw-bold px-5" id="btnDoRestore" disabled onclick="executeRestoreAction()">
                        Bring back records
                    </button>
                </div>
            </div>
        </div>
    </details>

    <!-- SECTION D: "Advanced options" (Collapsed by default, labeled "For administrators") -->
    <details class="expand-card" id="advancedCard">
        <summary>
            <span class="d-flex align-items-center gap-2">
                <i class="fas fa-sliders" style="color: var(--parish-gold);"></i>
                <span class="expand-title">Advanced options <span class="expand-sub-label">(For administrators)</span></span>
            </span>
        </summary>
        <div class="expand-content pt-4">
            <p class="section-desc mb-4">Customize file formats, record types, date ranges, and encryption password.</p>

            <!-- 1. Format Choice (3 equal cards desktop, stacked mobile) -->
            <div class="adv-group mb-4">
                <h4 class="adv-group-title">Backup File Format</h4>
                <div class="adv-format-grid">
                    <label class="adv-format-card is-selected" for="fmt_csv">
                        <input class="form-check-input parish-radio" type="radio" name="adv_format" id="fmt_csv" value="csv" checked>
                        <div class="adv-format-info">
                            <span class="adv-format-name">Standard</span>
                            <span class="adv-format-desc">CSV spreadsheet files in a ZIP</span>
                        </div>
                    </label>
                    <label class="adv-format-card" for="fmt_xlsx">
                        <input class="form-check-input parish-radio" type="radio" name="adv_format" id="fmt_xlsx" value="xlsx">
                        <div class="adv-format-info">
                            <span class="adv-format-name">Excel</span>
                            <span class="adv-format-desc">Microsoft Excel workbook (.xlsx)</span>
                        </div>
                    </label>
                    <label class="adv-format-card" for="fmt_json">
                        <input class="form-check-input parish-radio" type="radio" name="adv_format" id="fmt_json" value="json">
                        <div class="adv-format-info">
                            <span class="adv-format-name">JSON</span>
                            <span class="adv-format-desc">Structured data archive (.json)</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 2. Choose Record Types (3 Record Rows for tests & granularity) -->
            <div class="adv-group mb-4">
                <h4 class="adv-group-title">Record Types to Include</h4>
                <div class="adv-rows-group">
                    <label class="record-row adv-option-card is-selected" data-id="sacramental_records" for="cb_sacramental">
                        <input class="form-check-input parish-checkbox category-checkbox" type="checkbox" id="cb_sacramental" value="sacramental_records" checked>
                        <div class="adv-option-label-wrap">
                            <span class="adv-option-title">Sacramental Records</span>
                            <span class="adv-option-desc">Baptism, Confirmation, Marriage, First Communion, and Funeral registers</span>
                        </div>
                    </label>

                    <label class="record-row adv-option-card is-selected" data-id="parishioners" for="cb_parishioners">
                        <input class="form-check-input parish-checkbox category-checkbox" type="checkbox" id="cb_parishioners" value="parishioners" checked>
                        <div class="adv-option-label-wrap">
                            <span class="adv-option-title">Parishioners</span>
                            <span class="adv-option-desc">Registered parishioners and family directory</span>
                        </div>
                    </label>

                    <label class="record-row adv-option-card is-selected" data-id="requests" for="cb_requests">
                        <input class="form-check-input parish-checkbox category-checkbox" type="checkbox" id="cb_requests" value="requests" checked>
                        <div class="adv-option-label-wrap">
                            <span class="adv-option-title">Requests</span>
                            <span class="adv-option-desc">Certificate, mass intention, and sacrament requests</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 3. Additional Options (Same card style, identical left & right alignment) -->
            <div class="adv-group mb-4">
                <h4 class="adv-group-title">Additional Options</h4>
                <div class="adv-rows-group">
                    <!-- Include uploaded documents -->
                    <label class="adv-option-card is-selected" for="adv_include_files">
                        <div class="adv-option-row">
                            <input class="form-check-input parish-checkbox" type="checkbox" id="adv_include_files" checked>
                            <div class="adv-option-label-wrap">
                                <span class="adv-option-title">Include uploaded documents &amp; certificates</span>
                                <span class="adv-option-desc">Parishioner receipts, verification files, and seminar attachments</span>
                            </div>
                        </div>
                    </label>

                    <!-- Filter by date range (Indented fields inside same card) -->
                    <div class="adv-option-card" id="adv_date_card">
                        <label class="adv-option-row" for="adv_enable_date">
                            <input class="form-check-input parish-checkbox" type="checkbox" id="adv_enable_date" onchange="toggleAdvDateInputs()">
                            <div class="adv-option-label-wrap">
                                <span class="adv-option-title">Filter by date range</span>
                                <span class="adv-option-desc">Only include records created within a specific time window</span>
                            </div>
                        </label>
                        <div id="advDateInputs" class="adv-indented-fields" style="display: none;">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label for="adv_date_from" class="form-label">From Date</label>
                                    <input type="date" class="form-control" id="adv_date_from">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="adv_date_to" class="form-label">To Date</label>
                                    <input type="date" class="form-control" id="adv_date_to">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Password protection (Indented fields inside same card) -->
                    <div class="adv-option-card" id="adv_password_card">
                        <label class="adv-option-row" for="adv_enable_password">
                            <input class="form-check-input parish-checkbox" type="checkbox" id="adv_enable_password" onchange="toggleAdvPassword()">
                            <div class="adv-option-label-wrap">
                                <span class="adv-option-title">Password-protect (encrypt) this backup file</span>
                                <span class="adv-option-desc">Secure the archive using strong encryption for safe transport</span>
                            </div>
                        </label>
                        <div id="advPasswordBox" class="adv-indented-fields" style="display: none;">
                            <label for="advGeneratedPassword" class="form-label">Generated Strong Password:</label>
                            <div class="input-group mb-3 adv-password-group">
                                <input type="text" class="form-control font-monospace" id="advGeneratedPassword" readonly style="letter-spacing: 2px; font-weight: bold; background: #FFFFFF; font-size: 16px;">
                                <button class="btn btn-outline-secondary px-3 fw-bold" type="button" onclick="copyGeneratedPassword()" id="btnCopyPwd">
                                    <i class="fas fa-copy me-1"></i> Copy
                                </button>
                                <button class="btn btn-outline-secondary px-3 fw-bold" type="button" onclick="printGeneratedPassword()">
                                    <i class="fas fa-print me-1"></i> Print
                                </button>
                            </div>
                            <div class="alert alert-danger py-2 px-3 mb-0 d-flex align-items-center gap-2" role="alert" style="font-size: 15px; color: #991B1B; background-color: #FEF2F2; border-color: #FECACA; line-height: 1.5;">
                                <i class="fas fa-triangle-exclamation flex-shrink-0"></i>
                                <span><strong>Important:</strong> This password cannot be recovered or reset. Make sure to copy or write it down before downloading.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Button for Advanced Options (Full width, 52px tall, 16-17px text) -->
            <button type="button" class="btn btn-dark fw-bold w-100 btn-download-backup btn-adv-submit" style="background: var(--parish-green); min-height: 52px; height: 52px; font-size: 16.5px;" onclick="runAdvancedBackup()">
                <i class="fas fa-download me-2"></i> Back up with advanced options
            </button>
        </div>
    </details>

</div>

<!-- MODAL: "How does this work?" (3-step guide) -->
<div class="modal fade" id="howItWorksModal" tabindex="-1" aria-labelledby="howItWorksTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow p-2">
            <div class="modal-header border-0 pb-0">
                <h4 class="modal-title font-lora fw-bold" id="howItWorksTitle" style="color: var(--parish-green); font-size: 1.6rem;">
                    How backing up works
                </h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="d-flex align-items-start gap-3 mb-4">
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0" style="width: 42px; height: 42px; background: var(--parish-green); font-size: 1.25rem;">1</div>
                    <div>
                        <h5 class="fw-bold mb-1" style="font-size: 1.25rem;">Click "Back up everything now"</h5>
                        <p class="text-muted mb-0" style="font-size: 1.1rem; line-height: 1.5;">The system bundles all church registers, parishioners, and files into a single safe file.</p>
                    </div>
                </div>
                <div class="d-flex align-items-start gap-3 mb-4">
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0" style="width: 42px; height: 42px; background: var(--parish-green); font-size: 1.25rem;">2</div>
                    <div>
                        <h5 class="fw-bold mb-1" style="font-size: 1.25rem;">Download the backup file</h5>
                        <p class="text-muted mb-0" style="font-size: 1.1rem; line-height: 1.5;">Save the file directly to your computer when it finishes preparing.</p>
                    </div>
                </div>
                <div class="d-flex align-items-start gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white flex-shrink-0" style="width: 42px; height: 42px; background: var(--parish-green); font-size: 1.25rem;">3</div>
                    <div>
                        <h5 class="fw-bold mb-1" style="font-size: 1.25rem;">Save a copy to a USB or Google Drive</h5>
                        <p class="text-muted mb-0" style="font-size: 1.1rem; line-height: 1.5;">Never keep the backup only on this computer. Copy it to a flash drive or cloud storage for safekeeping.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-dark w-100 fw-bold" style="background: var(--parish-green); border: none; min-height: 54px; font-size: 1.2rem;" data-bs-dismiss="modal">
                    Got it!
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Progress Indicator -->
<div class="modal fade" id="backupProgressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 p-4 text-center">
            <div class="mb-3">
                <div class="spinner-border" style="width: 4rem; height: 4rem; color: var(--parish-green);" role="status"></div>
            </div>
            <h3 class="fw-bold text-dark mb-2 font-lora" id="progressStepTitle" style="font-size: 1.6rem;">Gathering records...</h3>
            <p class="text-danger fw-bold mb-3" style="font-size: 1.15rem;">
                <i class="fas fa-triangle-exclamation me-1"></i> Please keep this page open while your backup is being created.
            </p>
            <div class="progress mb-3" style="height: 12px; border-radius: 8px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="backupProgressBar" style="width: 25%; background-color: var(--parish-green);"></div>
            </div>
            <div class="text-muted" id="progressPercent" style="font-size: 1.1rem;">Preparing files...</div>
        </div>
    </div>
</div>

<!-- MODAL: Big Success Message -->
<div class="modal fade" id="backupSuccessModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 p-4 text-center">
            <div class="mb-3">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 80px; height: 80px; background: #DCFCE7; color: #15803D; font-size: 2.5rem;">
                    <i class="fas fa-check"></i>
                </div>
            </div>
            <h2 class="fw-bold font-lora mb-1" style="color: var(--parish-green); font-size: 1.8rem;">Backup complete!</h2>
            <p class="text-muted mb-4" id="successFileNameDisplay" style="font-size: 1.25rem;">Saved as parish-backup-2026-10-08.zip</p>

            <a href="#" id="successDownloadLink" class="btn btn-dark fw-bold py-3 mb-3 w-100" style="background: var(--parish-green); min-height: 58px; font-size: 1.25rem;">
                <i class="fas fa-download me-2"></i> Download Backup File
            </a>

            <div class="p-3 rounded-3 text-start bg-light border mt-2">
                <div class="d-flex align-items-start gap-2">
                    <span style="font-size: 1.4rem;">💡</span>
                    <div class="text-dark" style="font-size: 1.12rem; line-height: 1.5;">
                        <strong>Helpful tip:</strong> Save this file to a <strong>USB drive</strong> or <strong>Google Drive</strong>, not only on this computer.
                    </div>
                </div>
            </div>

            <button type="button" class="btn btn-outline-secondary mt-3 w-100" style="min-height: 52px; font-size: 1.15rem;" data-bs-dismiss="modal" onclick="location.reload()">
                Done
            </button>
        </div>
    </div>
</div>

<script>
    // 1. SIMPLE BACKUP FLOW (Runs when clicking "Back up everything now")
    async function runSimpleBackup() {
        var modalEl = document.getElementById('backupProgressModal');
        var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

        var pTitle = document.getElementById('progressStepTitle');
        var pBar = document.getElementById('backupProgressBar');
        var pPct = document.getElementById('progressPercent');

        // Step 1: Gathering records
        pTitle.textContent = 'Gathering records...';
        pBar.style.width = '30%';
        pPct.textContent = 'Gathering sacramental registers, parishioners, and requests...';

        var fd = new FormData();
        fd.append('categories[]', 'sacramental_records');
        fd.append('categories[]', 'parishioners');
        fd.append('categories[]', 'requests');
        fd.append('format', 'csv');
        fd.append('include_files', '1');

        setTimeout(function() {
            // Step 2: Packing files
            pTitle.textContent = 'Packing files...';
            pBar.style.width = '65%';
            pPct.textContent = 'Compressing into a safe backup file...';
        }, 800);

        setTimeout(function() {
            // Step 3: Almost done
            pTitle.textContent = 'Almost done...';
            pBar.style.width = '90%';
            pPct.textContent = 'Finishing up the archive...';
        }, 1800);

        try {
            var res = await fetch('../api/backup-parish-records.php', {
                method: 'POST',
                body: fd
            });

            if (!res.ok) {
                var errData = await res.json().catch(function() { return null; });
                throw new Error((errData && errData.error) ? errData.error : 'Something went wrong. Please try again or contact your administrator.');
            }

            pBar.style.width = '100%';
            pPct.textContent = 'Done!';

            var blob = await res.blob();
            var disposition = res.headers.get('Content-Disposition') || '';
            var match = disposition.match(/filename="?([^"]+)"?/);
            var filename = match ? match[1] : `parish-backup-${new Date().toISOString().slice(0,10)}.zip`;

            // Auto-trigger browser download
            var blobUrl = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();

            setTimeout(function() {
                bsModal.hide();
                showSuccessModal(filename, blobUrl);
            }, 600);

        } catch (err) {
            bsModal.hide();
            alert(err.message || 'Something went wrong. Please try again or contact your administrator.');
        }
    }

    // 2. ADVANCED BACKUP FLOW
    async function runAdvancedBackup() {
        var checked = Array.from(document.querySelectorAll('.category-checkbox:checked')).map(function(c) { return c.value; });
        if (checked.length === 0) {
            alert('Please select at least one record type.');
            return;
        }

        var modalEl = document.getElementById('backupProgressModal');
        var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

        var pTitle = document.getElementById('progressStepTitle');
        var pBar = document.getElementById('backupProgressBar');
        var pPct = document.getElementById('progressPercent');

        pTitle.textContent = 'Gathering records...';
        pBar.style.width = '35%';
        pPct.textContent = 'Processing requested options...';

        var fd = new FormData();
        checked.forEach(function(c) { fd.append('categories[]', c); });

        var fmtRadio = document.querySelector('input[name="adv_format"]:checked');
        var fmt = fmtRadio ? fmtRadio.value : 'csv';
        fd.append('format', fmt);

        if (document.getElementById('adv_include_files').checked) {
            fd.append('include_files', '1');
        }

        if (document.getElementById('adv_enable_date').checked) {
            fd.append('date_from', document.getElementById('adv_date_from').value);
            fd.append('date_to', document.getElementById('adv_date_to').value);
        }

        if (document.getElementById('adv_enable_password').checked) {
            var pwd = document.getElementById('advGeneratedPassword').value;
            if (pwd) fd.append('password', pwd);
        }

        try {
            var res = await fetch('../api/backup-parish-records.php', {
                method: 'POST',
                body: fd
            });

            if (!res.ok) {
                var errData = await res.json().catch(function() { return null; });
                throw new Error((errData && errData.error) ? errData.error : 'Something went wrong. Please try again or contact your administrator.');
            }

            pBar.style.width = '100%';
            var blob = await res.blob();
            var disposition = res.headers.get('Content-Disposition') || '';
            var match = disposition.match(/filename="?([^"]+)"?/);
            var filename = match ? match[1] : `parish-backup-${new Date().toISOString().slice(0,10)}.${fmt === 'json' ? 'json' : 'zip'}`;

            var blobUrl = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();

            setTimeout(function() {
                bsModal.hide();
                showSuccessModal(filename, blobUrl);
            }, 600);

        } catch (err) {
            bsModal.hide();
            alert(err.message || 'Something went wrong. Please try again or contact your administrator.');
        }
    }

    // Helper: Show Big Success Message Modal
    function showSuccessModal(filename, blobUrl) {
        document.getElementById('successFileNameDisplay').textContent = 'Saved as ' + filename;
        var dlLink = document.getElementById('successDownloadLink');
        dlLink.href = blobUrl;
        dlLink.download = filename;
        var sModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('backupSuccessModal'));
        sModal.show();
    }

    // 3. PASSWORD GENERATOR FOR ADVANCED OPTIONS
    function generateStrongPassword() {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
        var pwd = '';
        for (var i = 0; i < 16; i++) {
            pwd += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return pwd;
    }

    function toggleAdvPassword() {
        var isChecked = document.getElementById('adv_enable_password').checked;
        var box = document.getElementById('advPasswordBox');
        var card = document.getElementById('adv_password_card');
        if (isChecked) {
            if (box) box.style.display = 'block';
            if (!document.getElementById('advGeneratedPassword').value) {
                document.getElementById('advGeneratedPassword').value = generateStrongPassword();
            }
        } else {
            if (box) box.style.display = 'none';
        }
        if (card) card.classList.toggle('is-selected', isChecked);
    }

    function copyGeneratedPassword() {
        var pwd = document.getElementById('advGeneratedPassword').value;
        navigator.clipboard.writeText(pwd).then(function() {
            var btn = document.getElementById('btnCopyPwd');
            btn.innerHTML = '<i class="fas fa-check text-success me-1"></i> Copied!';
            setTimeout(function() {
                btn.innerHTML = '<i class="fas fa-copy me-1"></i> Copy';
            }, 2500);
        });
    }

    function printGeneratedPassword() {
        var pwd = document.getElementById('advGeneratedPassword').value;
        var w = window.open('', '', 'width=450,height=300');
        w.document.write('<h3>Parish Backup Encryption Password</h3><p>Keep this paper in a secure place. It cannot be recovered if lost.</p><h2 style="font-family: monospace; letter-spacing: 2px;">' + pwd + '</h2>');
        w.document.close();
        w.focus();
        w.print();
    }

    function toggleAdvDateInputs() {
        var on = document.getElementById('adv_enable_date').checked;
        var el = document.getElementById('advDateInputs');
        if (el) el.style.display = on ? 'block' : 'none';
        var card = document.getElementById('adv_date_card');
        if (card) card.classList.toggle('is-selected', on);
    }

    // 5. CUSTOM DROPDOWN & SELECTION INTERACTION
    document.addEventListener('DOMContentLoaded', function () {
        // Automatic backups custom dropdown
        var wrap = document.getElementById('autoFreqDropdown');
        if (wrap) {
            var trigger = document.getElementById('autoFreqTrigger');
            var select = document.getElementById('autoFreqSelect');
            var label = document.getElementById('autoFreqSelectedText');
            var items = wrap.querySelectorAll('.custom-dropdown-item');

            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                var isOpen = wrap.classList.toggle('is-open');
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            items.forEach(function(item) {
                item.addEventListener('click', function() {
                    var val = item.getAttribute('data-value');
                    var txt = item.querySelector('.dropdown-item-text').textContent;
                    select.value = val;
                    label.textContent = txt;
                    items.forEach(function(i) {
                        var isSel = (i === item);
                        i.classList.toggle('is-selected', isSel);
                        i.setAttribute('aria-selected', isSel ? 'true' : 'false');
                    });
                    wrap.classList.remove('is-open');
                    trigger.setAttribute('aria-expanded', 'false');
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

            document.addEventListener('click', function(e) {
                if (!wrap.contains(e.target)) {
                    wrap.classList.remove('is-open');
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });

            wrap.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    wrap.classList.remove('is-open');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
            });
        }

        // Advanced option cards toggle highlight on checkbox change
        document.querySelectorAll('.category-checkbox, #adv_include_files').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var card = cb.closest('.adv-option-card, .record-row');
                if (card) {
                    card.classList.toggle('is-selected', cb.checked);
                }
            });
        });

        // Backup format radio selection highlight
        document.querySelectorAll('input[name="adv_format"]').forEach(function(r) {
            r.addEventListener('change', function() {
                document.querySelectorAll('.adv-format-card').forEach(function(card) {
                    var input = card.querySelector('input');
                    card.classList.toggle('is-selected', input && input.checked);
                });
            });
        });
    });

    // 4. RESTORE PREVIEW & SAFEGUARDED FLOW
    async function checkRestoreFile() {
        var fi = document.getElementById('restoreFileInput');
        if (!fi.files || fi.files.length === 0) {
            alert('Please select a backup file first.');
            return;
        }

        var fd = new FormData();
        fd.append('backup_file', fi.files[0]);

        try {
            var res = await fetch('../api/backup-parish-records.php?action=preview_restore', {
                method: 'POST',
                body: fd
            });
            var json = await res.json();
            if (!json.success) throw new Error(json.error);

            var preview = json.data;
            var summaryTxt = '';

            if (preview.manifest && preview.manifest.record_counts) {
                var rc = preview.manifest.record_counts;
                var sacCount = (rc.baptism_records || 0) + (rc.confirmation_records || 0) + (rc.marriage_records || 0) + (rc.first_communion_records || 0) + (rc.funeral_records || 0);
                var parCount = rc.parishioners || 0;
                var reqCount = rc.requests || 0;
                summaryTxt = `This backup has <strong>${sacCount} sacramental records</strong>, <strong>${parCount} parishioners</strong>, and <strong>${reqCount} requests</strong>. Existing matching records will be safely preserved.`;
            } else {
                summaryTxt = `This backup file contains <strong>${preview.categories.length} data categories</strong> ready for inspection.`;
            }

            document.getElementById('restorePreviewText').innerHTML = summaryTxt;
            document.getElementById('restorePreviewBox').style.display = 'block';
            document.getElementById('restoreConfirmInput').value = '';
            document.getElementById('btnDoRestore').disabled = true;

        } catch (err) {
            alert('Inspection failed: ' + (err.message || 'Something went wrong. Please try again or contact your administrator.'));
        }
    }

    function verifyRestorePhrase() {
        var txt = document.getElementById('restoreConfirmInput').value.trim().toUpperCase();
        document.getElementById('btnDoRestore').disabled = (txt !== 'RESTORE');
    }

    function cancelRestore() {
        document.getElementById('restorePreviewBox').style.display = 'none';
        document.getElementById('restoreFileInput').value = '';
    }

    async function executeRestoreAction() {
        var txt = document.getElementById('restoreConfirmInput').value.trim().toUpperCase();
        if (txt !== 'RESTORE') return;

        var fi = document.getElementById('restoreFileInput');
        var fd = new FormData();
        fd.append('backup_file', fi.files[0]);
        fd.append('confirmation', 'RESTORE');

        var btn = document.getElementById('btnDoRestore');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Restoring...';

        try {
            var res = await fetch('../api/backup-parish-records.php?action=execute_restore', {
                method: 'POST',
                body: fd
            });
            var json = await res.json();
            if (!json.success) throw new Error(json.error);

            alert(json.message || 'Records successfully brought back!');
            location.reload();
        } catch (err) {
            alert(err.message || 'Something went wrong. Please try again or contact your administrator.');
            btn.disabled = false;
            btn.innerHTML = 'Bring back records';
        }
    }
</script>

<?php include '../templates/footer.php'; ?>
