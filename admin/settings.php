<?php
/**
 * Parish System Settings & Certificate Signatory Center
 * 
 * Manages official certificate signatory settings, clergy credentials,
 * system policies, and administrative configuration.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('system.settings');

$page_title = 'Parish Settings';
$error = '';
$success = '';

// Handle Settings POST submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_parish_clergy') {
            $priest_name = trim((string)($_POST['priest_in_charge'] ?? ''));
            $priest_title = trim((string)($_POST['priest_in_charge_title'] ?? 'Priest-in-Charge'));

            if ($priest_name === '') {
                throw new Exception('Please enter the Priest-in-Charge name.');
            }
            if ($priest_title === '') {
                throw new Exception('Please enter the Clergy Title / Position.');
            }

            writeSetting($conn, 'parish.priest_in_charge', $priest_name);
            writeSetting($conn, 'parish_priest_name', $priest_name);
            writeSetting($conn, 'parish.priest_in_charge_title', $priest_title);

            createAuditLog($conn, $_SESSION['user_id'] ?? 0, 'UPDATE_PARISH_CLERGY_SETTINGS', 'system_settings', 0, null, [
                'priest_in_charge' => $priest_name,
                'priest_in_charge_title' => $priest_title
            ]);

            $success = 'Certificate Signatory settings saved successfully.';
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

            $success = 'Automated backup settings updated successfully.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Read current settings
$cur_priest_in_charge = readSetting($conn, 'parish.priest_in_charge', 'REV. FR. HERIBERTO C. VILLAS, O.M.I.');
$cur_priest_title = readSetting($conn, 'parish.priest_in_charge_title', 'Priest-in-Charge');
$cur_auto_freq = readSetting($conn, 'backup.auto_frequency', 'weekly');
$cur_retention = intval(readSetting($conn, 'backup.retention_count', '10'));
$last_backup_time = readSetting($conn, 'last_parish_backup_time', '');

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Settings' => null
];
?>
<?php include '../templates/header.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,500;0,600;0,700;1,400&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    .settings-page-wrapper {
        font-family: 'Work Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background-color: #F7F3EA;
        padding: 32px 16px;
        min-height: calc(100vh - 120px);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 24px;
    }

    .settings-card {
        background-color: #FFFFFF;
        border: 1px solid #E7E0D2;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(44, 36, 24, 0.04), 0 6px 20px rgba(44, 36, 24, 0.03);
        width: 100%;
        max-width: 680px;
        overflow: hidden;
    }

    .settings-card-header {
        padding: 24px 28px 20px;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 1px solid #ECE4D6;
    }
    .settings-header-left {
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }
    .settings-badge-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background-color: #8C6427;
        color: #FAF7F2;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(140, 100, 39, 0.25);
    }
    .settings-title-group h1,
    .settings-title-group h2 {
        font-family: 'Lora', Georgia, serif;
        font-size: 1.35rem;
        font-weight: 600;
        color: #1C1917;
        margin: 0 0 4px 0;
    }
    .settings-title-group p {
        font-size: 0.88rem;
        color: #78716C;
        margin: 0;
    }

    .settings-card-body {
        padding: 24px 28px;
    }

    .form-control:focus, .form-select:focus {
        border-color: #8C6427;
        box-shadow: 0 0 0 3px rgba(140, 100, 39, 0.15);
    }

    .btn-save-clergy {
        background: #1E3626;
        color: #FAF7F2;
        font-weight: 600;
        font-size: 0.9rem;
        padding: 10px 24px;
        border-radius: 8px;
        border: none;
        transition: all 0.15s ease;
        cursor: pointer;
    }
    .btn-save-clergy:hover {
        background: #16291C;
        color: #FFFFFF;
        transform: translateY(-1px);
    }

    .quick-link-box {
        background: #FAF8F5;
        border: 1px solid #EAE3D6;
        border-radius: 12px;
        padding: 18px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
</style>

<div class="settings-page-wrapper">
    <!-- Messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 mb-0" style="max-width: 680px; width: 100%;" role="alert">
            <i class="fas fa-circle-exclamation me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 mb-0" style="max-width: 680px; width: 100%;" role="alert">
            <i class="fas fa-circle-check me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 1. Certificate Signatory Card (Task 1) -->
    <section class="settings-card" role="region" aria-label="Certificate Signatory">
        <header class="settings-card-header">
            <div class="settings-header-left">
                <div class="settings-badge-icon" aria-hidden="true">
                    <i class="fas fa-certificate" style="font-size: 1.25rem;"></i>
                </div>
                <div class="settings-title-group">
                    <h1>Certificate Signatory</h1>
                    <p>Official parish clergy signatory configured across all sacramental certificates.</p>
                </div>
            </div>
        </header>

        <form method="POST" id="parishClergyForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_parish_clergy">

            <div class="settings-card-body">
                <div class="mb-3">
                    <label for="priest_in_charge" class="form-label fw-bold text-dark" style="font-size: 0.88rem;">
                        <i class="fas fa-user-tie me-1 text-secondary"></i> Priest-in-Charge Name <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="priest_in_charge" name="priest_in_charge" 
                           value="<?php echo e($cur_priest_in_charge); ?>" required 
                           style="border-color: #DCD4C4; font-weight: 600; font-size: 0.95rem;"
                           placeholder="e.g. REV. FR. HERIBERTO C. VILLAS, O.M.I.">
                    <div class="form-text" style="font-size: 0.78rem;">
                        Printed on the official Certificate of Baptism, Confirmation, Marriage, and Communion signature lines.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="priest_in_charge_title" class="form-label fw-bold text-dark" style="font-size: 0.88rem;">
                        <i class="fas fa-tag me-1 text-secondary"></i> Clergy Title / Position <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="priest_in_charge_title" name="priest_in_charge_title" 
                           value="<?php echo e($cur_priest_title); ?>" required 
                           style="border-color: #DCD4C4; font-size: 0.95rem;"
                           placeholder="e.g. Priest-in-Charge">
                    <div class="form-text" style="font-size: 0.78rem;">
                        Printed directly underneath the signature line (e.g. Priest-in-Charge, Parish Priest).
                    </div>
                </div>

                <div class="d-flex justify-content-end pt-2">
                    <button type="submit" class="btn-save-clergy">
                        <i class="fas fa-save me-1"></i> Save Clergy Settings
                    </button>
                </div>
            </div>
        </form>
    </section>

    <!-- 2. Parish Continuity & Backup Quick Link -->
    <section class="settings-card" role="region" aria-label="Parish Records Backup">
        <header class="settings-card-header">
            <div class="settings-header-left">
                <div class="settings-badge-icon" style="background-color: #1E3626;" aria-hidden="true">
                    <i class="fas fa-database" style="font-size: 1.25rem;"></i>
                </div>
                <div class="settings-title-group">
                    <h2>Parish Records Backup &amp; Recovery</h2>
                    <p>Export records to CSV, Excel, or JSON, configure automated schedules, and restore archives.</p>
                </div>
            </div>
        </header>

        <div class="settings-card-body">
            <div class="quick-link-box mb-3">
                <div>
                    <h5 class="fs-6 fw-bold text-dark mb-1">Backup &amp; Restore Center</h5>
                    <p class="small text-muted mb-0">Generate fresh archives of Sacramental Registers, Parishioners, and Requests.</p>
                </div>
                <a href="backup.php" class="btn btn-sm text-white px-3 fw-semibold text-nowrap" style="background: #1E3626; border-radius: 8px;">
                    <i class="fas fa-box-archive me-1"></i> Open Backup Center
                </a>
            </div>

            <!-- Automated Backup Schedule Settings -->
            <form method="POST">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="save_auto_settings">
                <h6 class="small fw-bold text-dark mb-3"><i class="fas fa-calendar-check me-1 text-secondary"></i> Automated Backup Schedule</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Frequency</label>
                        <select name="auto_frequency" class="form-select form-select-sm">
                            <option value="off" <?php echo $cur_auto_freq === 'off' ? 'selected' : ''; ?>>Off (Manual Only)</option>
                            <option value="weekly" <?php echo $cur_auto_freq === 'weekly' ? 'selected' : ''; ?>>Weekly (Every Sunday)</option>
                            <option value="monthly" <?php echo $cur_auto_freq === 'monthly' ? 'selected' : ''; ?>>Monthly (1st of each month)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Retention Count (Keep last N)</label>
                        <input type="number" name="retention_count" class="form-control form-control-sm" value="<?php echo e($cur_retention); ?>" min="1" max="100">
                    </div>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-sm btn-outline-secondary px-3">
                        <i class="fas fa-clock me-1"></i> Update Schedule
                    </button>
                </div>
            </form>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
