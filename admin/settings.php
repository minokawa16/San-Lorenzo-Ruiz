<?php
/**
 * Backup, Recovery & Maintenance Center
 * Enterprise continuity tools for parish records, files, configuration, and recovery logs.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('system.settings');

$page_title = 'Backup, Recovery & Maintenance Center';
$error = '';
$success = '';
$validation_result = null;
$backup_dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'backups';
$project_root = realpath(__DIR__ . '/..');

// Recovery Schema - Creates logs and settings tables used by backup and maintenance tools.
function ensureRecoverySchema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS recovery_logs (
        recovery_id INT PRIMARY KEY AUTO_INCREMENT,
        admin_id INT NULL,
        recovery_type VARCHAR(80) NOT NULL,
        backup_file VARCHAR(255) NULL,
        files_restored INT DEFAULT 0,
        status VARCHAR(40) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recovery_logs_created (created_at)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS maintenance_logs (
        maintenance_id INT PRIMARY KEY AUTO_INCREMENT,
        admin_id INT NULL,
        maintenance_type VARCHAR(80) NOT NULL,
        status VARCHAR(40) NOT NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_maintenance_logs_created (created_at)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

ensureRecoverySchema($conn);

// Recovery Scopes - Defines which functional areas can be backed up or restored independently.
function recoveryScopes() {
    return [
        'entire_system' => 'Entire System',
        'database_only' => 'Database Only',
        'sacramental_records' => 'Sacramental Records Only',
        'user_accounts' => 'User Accounts Only',
        'documents' => 'Documents Only',
        'announcements' => 'Announcements Only'
    ];
}

// Scope Tables Function - Documents this helper's role in the parish management workflow.
function scopeTables($scope) {
    $map = [
        'sacramental_records' => [
            'baptism_records', 'confirmation_records', 'marriage_records', 'funeral_records',
            'communion_records', 'first_communion_records', 'death_records', 'sacramental_records',
            'certificates', 'certificate_requests', 'certificate_templates', 'requests',
            'request_documents', 'record_attachments'
        ],
        'user_accounts' => [
            'users', 'user_roles', 'roles', 'permissions', 'role_permissions',
            'login_history', 'login_attempts', 'audit_log', 'audit_logs', 'notification_preferences'
        ],
        'announcements' => [
            'announcements', 'announcement_recipients', 'announcement_attachments', 'notification_logs', 'notifications'
        ]
    ];

    return $map[$scope] ?? [];
}

// Backup Coverage Items Function - Documents this helper's role in the parish management workflow.
function backupCoverageItems() {
    return [
        'Parish Records' => ['Baptism', 'Confirmation', 'Marriage', 'Death', 'First Communion', 'Certificates', 'Attachments'],
        'Transactions' => ['Certificate requests', 'Reservations', 'Appointment schedules', 'Request history', 'Payments'],
        'User Management' => ['Parishioners', 'Administrators', 'Staff', 'Roles and permissions', 'Login history'],
        'Communication' => ['Announcements', 'Notifications', 'Email logs', 'OTP records', 'Chatbot logs'],
        'System Data' => ['Activity logs', 'Audit trails', 'Analytics', 'Dashboard statistics', 'AI assistant configuration'],
        'Files & Configuration' => ['PDFs', 'Images', 'Profile photos', 'Scanned certificates', 'SMTP settings', 'Source code']
    ];
}

// Backup Storage - Ensures backup folders are writable and protected from direct browsing.
function ensureBackupDirectory($backup_dir) {
    if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) {
        return false;
    }

    $index_file = $backup_dir . DIRECTORY_SEPARATOR . 'index.php';
    if (!file_exists($index_file)) {
        file_put_contents($index_file, "<?php\nhttp_response_code(403);\nexit('Access denied');\n");
    }

    $htaccess_file = $backup_dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess_file)) {
        file_put_contents($htaccess_file, "Options -Indexes\nRequire all denied\nDeny from all\n");
    }

    return is_writable($backup_dir);
}

// SQL Serialization - Escapes values before writing database rows into backup files.
function sqlValue($conn, $value) {
    if ($value === null) {
        return 'NULL';
    }

    return "'" . $conn->real_escape_string((string) $value) . "'";
}

// Create Database Backup Function - Documents this helper's role in the parish management workflow.
function createDatabaseBackup($conn, $backup_dir, $prefix = 'database-backup') {
    if (!ensureBackupDirectory($backup_dir)) {
        throw new Exception('Backup folder is not writable.');
    }

    $filename = $prefix . '-' . date('Ymd-His') . '.sql';
    $path = $backup_dir . DIRECTORY_SEPARATOR . $filename;
    $handle = fopen($path, 'w');
    if (!$handle) {
        throw new Exception('Unable to create database backup file.');
    }

    fwrite($handle, "-- Parish Management System Database Backup\n");
    fwrite($handle, "-- Created: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Database: " . DB_NAME . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables_result = $conn->query('SHOW TABLES');
    if (!$tables_result) {
        fclose($handle);
        throw new Exception('Unable to read database tables: ' . $conn->error);
    }

    while ($table_row = $tables_result->fetch_array()) {
        $table = $table_row[0];
        $safe_table = str_replace('`', '``', $table);

        fwrite($handle, "DROP TABLE IF EXISTS `$safe_table`;\n");
        $create_result = $conn->query("SHOW CREATE TABLE `$safe_table`");
        if (!$create_result) {
            fclose($handle);
            throw new Exception("Unable to read table structure for $table: " . $conn->error);
        }

        $create_row = $create_result->fetch_assoc();
        fwrite($handle, $create_row['Create Table'] . ";\n\n");

        $rows_result = $conn->query("SELECT * FROM `$safe_table`");
        if (!$rows_result) {
            fclose($handle);
            throw new Exception("Unable to read rows from $table: " . $conn->error);
        }

        while ($row = $rows_result->fetch_assoc()) {
            $columns = array_map(function ($column) {
                return '`' . str_replace('`', '``', $column) . '`';
            }, array_keys($row));
            $values = array_map(function ($value) use ($conn) {
                return sqlValue($conn, $value);
            }, array_values($row));

            fwrite($handle, "INSERT INTO `$safe_table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n");
        }

        fwrite($handle, "\n");
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    return $path;
}

// Backup Metadata - Generates checksums used to validate recovery packages.
function backupChecksum($path) {
    return is_file($path) ? hash_file('sha256', $path) : '';
}

// Collect Table Counts Function - Documents this helper's role in the parish management workflow.
function collectTableCounts($conn) {
    $tables = [];
    $tables_result = $conn->query('SHOW TABLES');
    while ($tables_result && $row = $tables_result->fetch_array()) {
        $table = $row[0];
        $safe_table = str_replace('`', '``', $table);
        $count_result = $conn->query("SELECT COUNT(*) AS count FROM `$safe_table`");
        $tables[$table] = $count_result ? intval($count_result->fetch_assoc()['count'] ?? 0) : 0;
    }
    return $tables;
}

// Count Project Files Function - Documents this helper's role in the parish management workflow.
function countProjectFiles($project_root) {
    $paths = ['uploads', 'assets', 'templates', 'includes', 'database', 'config', 'admin', 'users', 'auth', 'api', 'logs'];
    $file_count = 0;
    foreach ($paths as $path) {
        $full_path = $project_root . DIRECTORY_SEPARATOR . $path;
        if (is_dir($full_path)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full_path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $file_count++;
                }
            }
        }
    }
    return $file_count;
}

// Build Recovery Metadata Function - Documents this helper's role in the parish management workflow.
function buildRecoveryMetadata($conn, $project_root, $database_backup, $scope = 'complete_system') {
    return [
        'system' => 'Tugon Parish Management System',
        'backup_kind' => $scope,
        'created_at' => date('c'),
        'server' => $_SERVER['SERVER_NAME'] ?? 'localhost',
        'database_name' => DB_NAME,
        'database_backup' => basename($database_backup),
        'database_sha256' => backupChecksum($database_backup),
        'tables' => collectTableCounts($conn),
        'included_file_count' => countProjectFiles($project_root),
        'coverage' => backupCoverageItems(),
        'retention_policy' => [
            'daily' => '30 days',
            'weekly' => '6 months',
            'monthly' => '2 years'
        ],
        'recovery_scopes' => array_keys(recoveryScopes())
    ];
}

// File Backup - Adds project source files and uploads to the recovery package safely.
function addProjectFilesToZip($zip, $project_root, $backup_dir) {
    $root_length = strlen($project_root) + 1;
    $backup_real = realpath($backup_dir);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($project_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $real_path = realpath($path);

        if ($backup_real && $real_path && strpos($real_path, $backup_real) === 0) {
            continue;
        }

        $relative_path = str_replace('\\', '/', substr($path, $root_length));
        if ($file->isDir()) {
            $zip->addEmptyDir($relative_path);
        } else {
            $zip->addFile($path, $relative_path);
        }
    }
}

// Create Full Backup Function - Documents this helper's role in the parish management workflow.
function createFullBackup($conn, $backup_dir, $project_root, $backup_type = 'complete-system') {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive is not enabled. Enable the zip extension in XAMPP to create full recovery packages.');
    }

    if (!ensureBackupDirectory($backup_dir)) {
        throw new Exception('Backup folder is not writable.');
    }

    $database_backup = createDatabaseBackup($conn, $backup_dir, $backup_type . '-database');
    $filename = $backup_type . '-recovery-' . date('Ymd-His') . '.zip';
    $path = $backup_dir . DIRECTORY_SEPARATOR . $filename;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Unable to create full backup ZIP file.');
    }

    addProjectFilesToZip($zip, $project_root, $backup_dir);
    $zip->addFile($database_backup, 'RECOVERY/database.sql');
    $metadata = buildRecoveryMetadata($conn, $project_root, $database_backup, $backup_type);
    $zip->addFromString('RECOVERY/manifest.json', json_encode($metadata, JSON_PRETTY_PRINT));
    $zip->addFromString('RECOVERY/README.txt', "Complete System Recovery Backup\nCreated: " . date('Y-m-d H:i:s') . "\n\nThis package includes database records, uploaded files, application source, templates, assets, configuration, logs, and recovery metadata.\n");
    $zip->close();

    return $path;
}

// Backup Listing - Reads available recovery packages and database dumps for the admin UI.
function getBackupFiles($backup_dir) {
    if (!is_dir($backup_dir)) {
        return [];
    }

    $files = glob($backup_dir . DIRECTORY_SEPARATOR . '*.{sql,zip}', GLOB_BRACE) ?: [];
    usort($files, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    return $files;
}

// Validate Backup Package Function - Documents this helper's role in the parish management workflow.
function validateBackupPackage($path) {
    $result = [
        'valid' => false,
        'type' => strtoupper(pathinfo($path, PATHINFO_EXTENSION)),
        'file' => basename($path),
        'checks' => [],
        'manifest' => [],
        'summary' => []
    ];

    if (!is_file($path)) {
        $result['checks'][] = ['status' => 'critical', 'text' => 'Backup file does not exist.'];
        return $result;
    }

    $result['checks'][] = ['status' => 'healthy', 'text' => 'File exists.'];
    $result['checks'][] = ['status' => filesize($path) > 0 ? 'healthy' : 'critical', 'text' => 'Size: ' . formatFileSize(filesize($path))];
    $result['checks'][] = ['status' => 'healthy', 'text' => 'SHA-256: ' . backupChecksum($path)];

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'sql') {
        $head = file_get_contents($path, false, null, 0, 4096);
        $has_structure = strpos($head, 'SET FOREIGN_KEY_CHECKS') !== false || strpos($head, 'CREATE TABLE') !== false;
        $result['valid'] = $has_structure;
        $result['checks'][] = ['status' => $has_structure ? 'healthy' : 'critical', 'text' => $has_structure ? 'SQL database structure detected.' : 'SQL structure marker not found.'];
        $result['summary'][] = 'Database-only recovery file.';
    } elseif ($ext === 'zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $has_db = $zip->locateName('RECOVERY/database.sql') !== false;
            $manifest_index = $zip->locateName('RECOVERY/manifest.json');
            $result['checks'][] = ['status' => $has_db ? 'healthy' : 'critical', 'text' => $has_db ? 'Database recovery SQL found.' : 'Database recovery SQL missing.'];
            if ($manifest_index !== false) {
                $manifest = json_decode($zip->getFromIndex($manifest_index), true);
                $result['manifest'] = is_array($manifest) ? $manifest : [];
                $result['checks'][] = ['status' => is_array($manifest) ? 'healthy' : 'warning', 'text' => is_array($manifest) ? 'Recovery manifest loaded.' : 'Recovery manifest could not be parsed.'];
            } else {
                $result['checks'][] = ['status' => 'warning', 'text' => 'Recovery manifest missing.'];
            }
            $result['summary'][] = $zip->numFiles . ' package entries found.';
            $result['valid'] = $has_db;
            $zip->close();
        } else {
            $result['checks'][] = ['status' => 'critical', 'text' => 'Unable to open ZIP package.'];
        }
    } elseif ($ext === 'zip') {
        $result['checks'][] = ['status' => 'warning', 'text' => 'ZipArchive is not enabled, so ZIP validation is limited.'];
        $result['valid'] = filesize($path) > 0;
    }

    return $result;
}

// System Settings - Stores maintenance schedule and recovery preferences in the database.
function writeSetting($conn, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($stmt) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

// Read Setting Function - Documents this helper's role in the parish management workflow.
function readSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row['setting_value'];
        }
    }
    return $default;
}

// Recovery Logs - Records backup and restore outcomes for audit purposes.
function insertRecoveryLog($conn, $admin_id, $type, $backup_file, $files_restored, $status, $details) {
    $stmt = $conn->prepare("INSERT INTO recovery_logs (admin_id, recovery_type, backup_file, files_restored, status, details) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $admin_id = intval($admin_id);
        $files_restored = intval($files_restored);
        $stmt->bind_param('ississ', $admin_id, $type, $backup_file, $files_restored, $status, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// Insert Maintenance Log Function - Documents this helper's role in the parish management workflow.
function insertMaintenanceLog($conn, $admin_id, $type, $status, $details) {
    $stmt = $conn->prepare("INSERT INTO maintenance_logs (admin_id, maintenance_type, status, details) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $admin_id = intval($admin_id);
        $stmt->bind_param('isss', $admin_id, $type, $status, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// SQL Restore Filtering - Limits database import statements to the selected recovery scope.
function shouldImportStatement($statement, $scope) {
    if (in_array($scope, ['entire_system', 'database_only'], true)) {
        return true;
    }

    if (preg_match('/^\s*SET\s+/i', $statement)) {
        return true;
    }

    $tables = scopeTables($scope);
    if (!$tables) {
        return false;
    }

    if (preg_match('/(?:TABLE IF EXISTS|TABLE|INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i', $statement, $matches)) {
        return in_array($matches[1], $tables, true);
    }

    return false;
}

// Import Sql File Function - Documents this helper's role in the parish management workflow.
function importSqlFile($conn, $sql_path, $scope) {
    if (!is_file($sql_path)) {
        throw new Exception('Database recovery SQL file was not found.');
    }

    $handle = fopen($sql_path, 'r');
    if (!$handle) {
        throw new Exception('Unable to read database recovery SQL.');
    }

    $executed = 0;
    $statement = '';
    while (($line = fgets($handle)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }

        $statement .= $line;
        if (substr(rtrim($line), -1) === ';') {
            if (shouldImportStatement($statement, $scope)) {
                if (!$conn->query($statement)) {
                    fclose($handle);
                    throw new Exception('Database restore failed: ' . $conn->error);
                }
                $executed++;
            }
            $statement = '';
        }
    }
    fclose($handle);

    return $executed;
}

// File Restore Filtering - Prevents recovery packages from writing outside allowed project areas.
function isZipPathAllowedForScope($relative_path, $scope) {
    $relative_path = str_replace('\\', '/', $relative_path);
    if ($relative_path === '' || strpos($relative_path, '../') !== false || strpos($relative_path, '/..') !== false) {
        return false;
    }

    if (strpos($relative_path, 'RECOVERY/') === 0 || strpos($relative_path, 'backups/') === 0) {
        return false;
    }

    if ($scope === 'documents') {
        return strpos($relative_path, 'uploads/') === 0;
    }

    if ($scope === 'entire_system') {
        return true;
    }

    return false;
}

// Restore Files From Zip Function - Documents this helper's role in the parish management workflow.
function restoreFilesFromZip($zip_path, $project_root, $scope) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive is not enabled, so file recovery cannot run.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        throw new Exception('Unable to open recovery package.');
    }

    $restored = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (!isZipPathAllowedForScope($entry, $scope)) {
            continue;
        }

        $target = $project_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
        $target_dir = dirname($target);
        $resolved_parent = realpath($target_dir);
        if (!$resolved_parent && !mkdir($target_dir, 0755, true)) {
            continue;
        }

        $resolved_parent = realpath($target_dir);
        if (!$resolved_parent || strpos($resolved_parent, $project_root) !== 0) {
            continue;
        }

        if (substr($entry, -1) === '/') {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
            continue;
        }

        $stream = $zip->getStream($entry);
        if (!$stream) {
            continue;
        }
        $out = fopen($target, 'w');
        if ($out) {
            stream_copy_to_stream($stream, $out);
            fclose($out);
            $restored++;
        }
        fclose($stream);
    }

    $zip->close();
    return $restored;
}

// Recovery Package Reader - Extracts the embedded SQL backup from a complete system archive.
function extractDatabaseSqlFromZip($zip_path, $backup_dir) {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        throw new Exception('Unable to open recovery package.');
    }

    $sql = $zip->getFromName('RECOVERY/database.sql');
    $zip->close();
    if ($sql === false) {
        throw new Exception('RECOVERY/database.sql was not found in the package.');
    }

    $path = $backup_dir . DIRECTORY_SEPARATOR . 'restore-database-' . date('Ymd-His') . '.sql';
    file_put_contents($path, $sql);
    return $path;
}

// Run Recovery Function - Documents this helper's role in the parish management workflow.
function runRecovery($conn, $backup_path, $scope, $project_root, $backup_dir) {
    $validation = validateBackupPackage($backup_path);
    if (!$validation['valid']) {
        throw new Exception('Recovery package failed validation. Restore cancelled.');
    }

    $ext = strtolower(pathinfo($backup_path, PATHINFO_EXTENSION));
    $files_restored = 0;
    $statements = 0;

    if ($ext === 'zip') {
        if (in_array($scope, ['entire_system', 'documents'], true)) {
            $files_restored = restoreFilesFromZip($backup_path, $project_root, $scope);
        }
        if ($scope !== 'documents') {
            $sql_path = extractDatabaseSqlFromZip($backup_path, $backup_dir);
            $statements = importSqlFile($conn, $sql_path, $scope);
        }
    } elseif ($ext === 'sql') {
        if ($scope === 'documents') {
            throw new Exception('Documents recovery requires a full ZIP recovery package.');
        }
        $statements = importSqlFile($conn, $backup_path, $scope);
    }

    return [
        'files_restored' => $files_restored,
        'statements' => $statements
    ];
}

// Maintenance Runner - Creates monthly recovery packages and cleans old generated files.
function runMonthlyMaintenance($conn, $backup_dir, $project_root) {
    $details = [];
    $tables = $conn->query('SHOW TABLES');
    while ($tables && $row = $tables->fetch_array()) {
        $table = str_replace('`', '``', $row[0]);
        $conn->query("REPAIR TABLE `$table`");
        $conn->query("OPTIMIZE TABLE `$table`");
    }
    $details[] = 'Database tables repaired and optimized.';

    if (tableExists($conn, 'otp_codes')) {
        $conn->query("DELETE FROM otp_codes WHERE expires_at < NOW()");
        $details[] = 'Expired OTP records removed.';
    }
    if (tableExists($conn, 'email_verifications')) {
        $conn->query("DELETE FROM email_verifications WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND verified_at IS NULL");
        $details[] = 'Expired unverified email tokens removed.';
    }

    foreach ([$project_root . DIRECTORY_SEPARATOR . 'cache', $project_root . DIRECTORY_SEPARATOR . 'logs'] as $path) {
        if (is_dir($path)) {
            foreach (glob($path . DIRECTORY_SEPARATOR . 'tmp*') ?: [] as $tmp) {
                if (is_file($tmp) && filemtime($tmp) < time() - 86400) {
                    @unlink($tmp);
                }
            }
        }
    }
    $details[] = 'Cache and temporary files reviewed.';

    $valid_count = 0;
    $backup_files = getBackupFiles($backup_dir);
    foreach ($backup_files as $file) {
        $validation = validateBackupPackage($file);
        if ($validation['valid']) {
            $valid_count++;
        }
    }
    $details[] = $valid_count . ' backup file(s) passed validation.';

    writeSetting($conn, 'last_monthly_maintenance', date('Y-m-d H:i:s'));
    return $details;
}

// Health Metrics - Calculates backup folder size and dashboard status information.
function directorySize($path) {
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

// Latest Backup Time Function - Documents this helper's role in the parish management workflow.
function latestBackupTime($backup_files, $contains = '') {
    foreach ($backup_files as $file) {
        if ($contains === '' || stripos(basename($file), $contains) !== false) {
            return filemtime($file);
        }
    }
    return null;
}

// Status Class Function - Documents this helper's role in the parish management workflow.
function statusClass($status) {
    return [
        'healthy' => 'success',
        'warning' => 'warning',
        'critical' => 'danger'
    ][$status] ?? 'secondary';
}

// Health Label Function - Documents this helper's role in the parish management workflow.
function healthLabel($status) {
    return [
        'healthy' => 'Healthy',
        'warning' => 'Warning',
        'critical' => 'Critical'
    ][$status] ?? 'Unknown';
}

// Backup Status From Age Function - Documents this helper's role in the parish management workflow.
function backupStatusFromAge($timestamp, $warning_days, $critical_days) {
    if (!$timestamp) {
        return 'critical';
    }
    $days = (time() - $timestamp) / 86400;
    if ($days >= $critical_days) {
        return 'critical';
    }
    if ($days >= $warning_days) {
        return 'warning';
    }
    return 'healthy';
}

// Get Recent Rows Function - Documents this helper's role in the parish management workflow.
function getRecentRows($conn, $table, $order_column, $limit = 8) {
    if (!tableExists($conn, $table)) {
        return [];
    }
    $rows = [];
    $limit = max(1, intval($limit));
    $result = $conn->query("SELECT * FROM `$table` ORDER BY `$order_column` DESC LIMIT $limit");
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function getDatabaseStorageUsage($conn) {
    $db_name = defined('DB_NAME') ? DB_NAME : '';
    if ($db_name === '') {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.TABLES WHERE table_schema = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $db_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['bytes'] ?? 0);
}

function maintenanceCount($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return intval($row['count'] ?? 0);
}

function maintenanceAdminName($conn, $admin_id) {
    $admin_id = intval($admin_id);
    if ($admin_id <= 0 || !tableExists($conn, 'users')) {
        return 'System';
    }

    $stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return 'System';
    }
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['fullname'] : 'System';
}

function costValue($conn, $key, $default = '') {
    return readSetting($conn, 'ops_' . $key, $default);
}

function costFloat($conn, $key, $default = 0) {
    return max(0, floatval(costValue($conn, $key, $default)));
}

// Upload Handling - Saves manually uploaded recovery packages for validation and restore.
function saveUploadedRecoveryPackage($backup_dir) {
    if (empty($_FILES['recovery_package']['tmp_name'])) {
        return '';
    }

    $name = basename($_FILES['recovery_package']['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip', 'sql'], true)) {
        throw new Exception('Only ZIP and SQL recovery packages are accepted.');
    }

    if (!ensureBackupDirectory($backup_dir)) {
        throw new Exception('Backup folder is not writable.');
    }

    $target = $backup_dir . DIRECTORY_SEPARATOR . 'uploaded-recovery-' . date('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $name);
    if (!move_uploaded_file($_FILES['recovery_package']['tmp_name'], $target)) {
        throw new Exception('Unable to save uploaded recovery package.');
    }

    return $target;
}

// Retention Policy - Removes old generated backups according to configured limits.
function enforceRetentionPolicy($backup_dir) {
    $rules = [
        'daily' => 30,
        'weekly' => 183,
        'monthly' => 730
    ];
    $removed = 0;
    foreach (getBackupFiles($backup_dir) as $file) {
        $name = strtolower(basename($file));
        foreach ($rules as $type => $days) {
            if (strpos($name, $type) !== false && filemtime($file) < time() - ($days * 86400)) {
                @unlink($file);
                $removed++;
                break;
            }
        }
    }
    return $removed;
}

// Automated Maintenance - Runs scheduled database, file, and complete-system backups when due.
function runDueAutomatedTasks($conn, $backup_dir, $project_root, $admin_id) {
    if (readSetting($conn, 'backup_scheduler_enabled', '1') !== '1') {
        return [];
    }

    $messages = [];
    $now = time();
    $today = date('Y-m-d', $now);
    $daily_time = readSetting($conn, 'daily_backup_time', '01:00');
    $weekly_day = readSetting($conn, 'weekly_backup_day', 'Sunday');
    $monthly_day = intval(readSetting($conn, 'monthly_backup_day', '1'));
    $monthly_day = max(1, min(28, $monthly_day));
    $last_daily = readSetting($conn, 'last_daily_backup', '');
    $last_weekly = readSetting($conn, 'last_weekly_backup', '');
    $last_monthly = readSetting($conn, 'last_monthly_backup', '');
    $last_maintenance = readSetting($conn, 'last_monthly_maintenance', '');

    if (date('H:i', $now) >= $daily_time && (!$last_daily || date('Y-m-d', strtotime($last_daily)) !== $today)) {
        try {
            createDatabaseBackup($conn, $backup_dir, 'daily-database');
            writeSetting($conn, 'last_daily_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $admin_id, 'AUTO_DAILY_BACKUP', 'system', 0);
            $messages[] = 'Automated daily database backup completed.';
        } catch (Exception $e) {
            $messages[] = 'Automated daily backup skipped: ' . $e->getMessage();
        }
    }

    $weekly_due = date('l', $now) === $weekly_day && (!$last_weekly || strtotime($last_weekly) < strtotime('-6 days'));
    if ($weekly_due) {
        try {
            createFullBackup($conn, $backup_dir, $project_root, 'weekly-files');
            writeSetting($conn, 'last_weekly_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $admin_id, 'AUTO_WEEKLY_BACKUP', 'system', 0);
            $messages[] = 'Automated weekly file backup completed.';
        } catch (Exception $e) {
            $messages[] = 'Automated weekly backup skipped: ' . $e->getMessage();
        }
    }

    $monthly_due = intval(date('j', $now)) === $monthly_day && (!$last_monthly || date('Y-m', strtotime($last_monthly)) !== date('Y-m', $now));
    if ($monthly_due) {
        try {
            createFullBackup($conn, $backup_dir, $project_root, 'monthly-complete-system');
            writeSetting($conn, 'last_monthly_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $admin_id, 'AUTO_MONTHLY_COMPLETE_BACKUP', 'system', 0);
            $messages[] = 'Automated monthly complete system backup completed.';
        } catch (Exception $e) {
            $messages[] = 'Automated monthly backup skipped: ' . $e->getMessage();
        }
    }

    $maintenance_due = intval(date('j', $now)) === $monthly_day && (!$last_maintenance || date('Y-m', strtotime($last_maintenance)) !== date('Y-m', $now));
    if ($maintenance_due) {
        try {
            $details = runMonthlyMaintenance($conn, $backup_dir, $project_root);
            $removed = enforceRetentionPolicy($backup_dir);
            $details[] = $removed . ' expired backup file(s) removed by retention policy.';
            insertMaintenanceLog($conn, $admin_id, 'automated_monthly_maintenance', 'completed', implode("\n", $details));
            createAuditLog($conn, $admin_id, 'AUTO_MONTHLY_MAINTENANCE', 'system', 0);
            $messages[] = 'Automated monthly maintenance completed.';
        } catch (Exception $e) {
            insertMaintenanceLog($conn, $admin_id, 'automated_monthly_maintenance', 'failed', $e->getMessage());
            $messages[] = 'Automated monthly maintenance skipped: ' . $e->getMessage();
        }
    }

    return $messages;
}

if (isset($_GET['download'])) {
    $requested = basename($_GET['download']);
    $path = $backup_dir . DIRECTORY_SEPARATOR . $requested;
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (!in_array($extension, ['sql', 'zip'], true) || !is_file($path)) {
        http_response_code(404);
        exit('Backup file not found.');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'database_backup') {
            $path = createDatabaseBackup($conn, $backup_dir, 'daily-database');
            writeSetting($conn, 'last_daily_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'], 'CREATE_DATABASE_BACKUP', 'system', 0);
            $success = 'Database backup created: ' . basename($path);
        } elseif ($action === 'weekly_backup') {
            $path = createFullBackup($conn, $backup_dir, $project_root, 'weekly-files');
            writeSetting($conn, 'last_weekly_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'], 'CREATE_WEEKLY_BACKUP', 'system', 0);
            $success = 'Weekly database and file backup created: ' . basename($path);
        } elseif ($action === 'full_backup') {
            $path = createFullBackup($conn, $backup_dir, $project_root, 'monthly-complete-system');
            writeSetting($conn, 'last_monthly_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'], 'CREATE_COMPLETE_SYSTEM_BACKUP', 'system', 0);
            $success = 'Complete system recovery package created: ' . basename($path);
        } elseif ($action === 'save_schedule') {
            writeSetting($conn, 'backup_scheduler_enabled', isset($_POST['scheduler_enabled']) ? '1' : '0');
            writeSetting($conn, 'daily_backup_time', $_POST['daily_backup_time'] ?? '01:00');
            writeSetting($conn, 'weekly_backup_day', $_POST['weekly_backup_day'] ?? 'Sunday');
            writeSetting($conn, 'monthly_backup_day', $_POST['monthly_backup_day'] ?? '1');
            $success = 'Automated backup schedule settings saved.';
        } elseif ($action === 'save_operational_costs') {
            $text_fields = [
                'hosting_provider',
                'hosting_plan',
                'server_specs',
                'domain_name',
                'ssl_status',
                'backup_location',
                'subscription_notes'
            ];
            foreach ($text_fields as $field) {
                writeSetting($conn, 'ops_' . $field, trim($_POST[$field] ?? ''));
            }

            $cost_fields = [
                'hosting_monthly',
                'domain_monthly',
                'ssl_monthly',
                'sms_monthly',
                'email_monthly',
                'backup_monthly',
                'maintenance_monthly'
            ];
            foreach ($cost_fields as $field) {
                writeSetting($conn, 'ops_' . $field, number_format(max(0, floatval($_POST[$field] ?? 0)), 2, '.', ''));
            }

            insertMaintenanceLog($conn, $_SESSION['user_id'], 'operational_cost_update', 'completed', 'Hosting requirements and operational cost documentation updated.');
            createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_OPERATIONAL_COSTS', 'system', 0);
            $success = 'Hosting and operational cost monitoring details saved.';
        } elseif ($action === 'run_maintenance') {
            $details = runMonthlyMaintenance($conn, $backup_dir, $project_root);
            $removed = enforceRetentionPolicy($backup_dir);
            $details[] = $removed . ' expired backup file(s) removed by retention policy.';
            insertMaintenanceLog($conn, $_SESSION['user_id'], 'monthly_maintenance', 'completed', implode("\n", $details));
            createAuditLog($conn, $_SESSION['user_id'], 'RUN_MONTHLY_MAINTENANCE', 'system', 0);
            $success = 'Monthly maintenance completed.';
        } elseif ($action === 'validate_backup') {
            $upload = saveUploadedRecoveryPackage($backup_dir);
            $selected = $upload ?: basename($_POST['backup_file'] ?? '');
            $path = $upload ?: ($backup_dir . DIRECTORY_SEPARATOR . $selected);
            $validation_result = validateBackupPackage($path);
            $success = $validation_result['valid'] ? 'Recovery package passed validation.' : 'Recovery package validation found issues.';
        } elseif ($action === 'restore_backup') {
            $selected = basename($_POST['backup_file'] ?? '');
            $scope = $_POST['recovery_scope'] ?? 'database_only';
            $confirmation = trim($_POST['confirmation'] ?? '');

            if (!isset(recoveryScopes()[$scope])) {
                throw new Exception('Invalid recovery scope selected.');
            }
            if ($confirmation !== 'RESTORE') {
                throw new Exception('Type RESTORE to confirm this recovery operation.');
            }

            $path = $backup_dir . DIRECTORY_SEPARATOR . $selected;
            $restore_result = runRecovery($conn, $path, $scope, $project_root, $backup_dir);
            insertRecoveryLog($conn, $_SESSION['user_id'], $scope, basename($path), $restore_result['files_restored'], 'completed', 'SQL statements executed: ' . $restore_result['statements']);
            createAuditLog($conn, $_SESSION['user_id'], 'RUN_SYSTEM_RECOVERY', 'system', 0, null, ['scope' => $scope, 'backup' => basename($path)]);
            $success = recoveryScopes()[$scope] . ' recovery completed. Files restored: ' . $restore_result['files_restored'] . '. SQL statements executed: ' . $restore_result['statements'] . '.';
        }
    } catch (Exception $e) {
        if (($action ?? '') === 'restore_backup') {
            insertRecoveryLog($conn, $_SESSION['user_id'] ?? 0, $_POST['recovery_scope'] ?? 'unknown', basename($_POST['backup_file'] ?? ''), 0, 'failed', $e->getMessage());
        }
        $error = $e->getMessage();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $automated_messages = runDueAutomatedTasks($conn, $backup_dir, $project_root, $_SESSION['user_id'] ?? 0);
    if ($automated_messages) {
        $success = trim($success . ' ' . implode(' ', $automated_messages));
    }
}

$backup_files = getBackupFiles($backup_dir);
$total_backup_size = directorySize($backup_dir);
$latest_backup = latestBackupTime($backup_files);
$last_daily = readSetting($conn, 'last_daily_backup', '');
$last_weekly = readSetting($conn, 'last_weekly_backup', '');
$last_monthly = readSetting($conn, 'last_monthly_backup', '');
$scheduler_enabled = readSetting($conn, 'backup_scheduler_enabled', '1') === '1';
$daily_time = readSetting($conn, 'daily_backup_time', '01:00');
$weekly_day = readSetting($conn, 'weekly_backup_day', 'Sunday');
$monthly_day = readSetting($conn, 'monthly_backup_day', '1');
$db_status = tableExists($conn, 'users') && tableExists($conn, 'audit_log') ? 'healthy' : 'warning';
$backup_status = backupStatusFromAge($latest_backup, 7, 30);
$storage_status = $total_backup_size > (2 * 1024 * 1024 * 1024) ? 'warning' : 'healthy';
$zip_status = class_exists('ZipArchive') ? 'healthy' : 'critical';
$recovery_readiness = ($backup_status === 'healthy' && $zip_status === 'healthy') ? 'healthy' : (($backup_status === 'critical' || $zip_status === 'critical') ? 'critical' : 'warning');
$recent_recovery_logs = getRecentRows($conn, 'recovery_logs', 'created_at', 8);
$recent_maintenance_logs = getRecentRows($conn, 'maintenance_logs', 'created_at', 8);
$db_storage_bytes = getDatabaseStorageUsage($conn);
$backup_storage_limit = 2 * 1024 * 1024 * 1024;
$backup_storage_percent = min(100, round(($total_backup_size / max(1, $backup_storage_limit)) * 100));
$memory_peak = memory_get_peak_usage(true);
$backup_success_total = tableExists($conn, 'recovery_logs') ? maintenanceCount($conn, "SELECT COUNT(*) AS count FROM recovery_logs") : 0;
$backup_success_completed = tableExists($conn, 'recovery_logs') ? maintenanceCount($conn, "SELECT COUNT(*) AS count FROM recovery_logs WHERE status = 'completed'") : 0;
$backup_success_rate = $backup_success_total > 0 ? round(($backup_success_completed / $backup_success_total) * 100) : ($latest_backup ? 100 : 0);
$critical_alerts = [];
if ($backup_status === 'critical') {
    $critical_alerts[] = 'No recent backup is available. Create a complete recovery package.';
}
if ($zip_status === 'critical') {
    $critical_alerts[] = 'PHP ZipArchive is disabled. Full recovery packages cannot be created or restored.';
}
if ($storage_status !== 'healthy') {
    $critical_alerts[] = 'Backup storage is approaching the configured local limit.';
}
$hosting_provider = costValue($conn, 'hosting_provider', 'Localhost / XAMPP');
$hosting_plan = costValue($conn, 'hosting_plan', 'Development machine');
$server_specs = costValue($conn, 'server_specs', 'Apache, PHP, MySQL on local workstation');
$domain_name = costValue($conn, 'domain_name', 'Not yet deployed');
$ssl_status = costValue($conn, 'ssl_status', 'Pending deployment');
$backup_location = costValue($conn, 'backup_location', 'Local backups folder');
$subscription_notes = costValue($conn, 'subscription_notes', 'SMTP, SMS gateway, and hosting subscriptions will be finalized before online deployment.');
$costs = [
    'Hosting' => costFloat($conn, 'hosting_monthly', 0),
    'Domain' => costFloat($conn, 'domain_monthly', 0),
    'SSL' => costFloat($conn, 'ssl_monthly', 0),
    'SMS Gateway' => costFloat($conn, 'sms_monthly', 0),
    'Email Service' => costFloat($conn, 'email_monthly', 0),
    'Backup Storage' => costFloat($conn, 'backup_monthly', 0),
    'Maintenance' => costFloat($conn, 'maintenance_monthly', 0)
];
$monthly_total = array_sum($costs);
$annual_total = $monthly_total * 12;
$max_cost = max(1, max($costs));

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Backup, Recovery & Maintenance Center' => null
];
?>
<?php include '../templates/header.php'; ?>

<style>
    .recovery-center { max-width: 1500px; margin: 0 auto; }
    .recovery-hero { background: #fff; color: #101828; border: 1px solid #e4e7ec; border-top: 4px solid #d7ad43; border-radius: 8px; padding: 28px; display: grid; grid-template-columns: 1.5fr .8fr; gap: 24px; align-items: center; box-shadow: 0 12px 28px rgba(16, 24, 40, .06); }
    .recovery-hero h1 { font-size: clamp(1.6rem, 3vw, 2.35rem); margin: 0 0 10px; letter-spacing: 0; }
    .recovery-hero p { color: #667085; margin-bottom: 0; max-width: 760px; }
    .hero-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; }
    .metric-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; margin: 18px 0; }
    .metric-card, .enterprise-card, .wizard-panel, .health-panel { background: #fff; border: 1px solid #e4e7ec; border-radius: 8px; box-shadow: 0 12px 28px rgba(16, 24, 40, .06); }
    .metric-card { padding: 16px; min-height: 118px; }
    .metric-label { color: #667085; font-size: .82rem; font-weight: 700; text-transform: uppercase; }
    .metric-value { font-size: 1.45rem; font-weight: 800; color: #101828; margin-top: 8px; word-break: break-word; }
    .metric-note { color: #667085; font-size: .86rem; margin-top: 4px; }
    .status-pill { display: inline-flex; align-items: center; gap: 7px; border-radius: 999px; padding: 5px 10px; font-weight: 700; font-size: .82rem; }
    .status-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
    .status-healthy { background: #ecfdf3; color: #027a48; }
    .status-warning { background: #fffaeb; color: #b54708; }
    .status-critical { background: #fef3f2; color: #b42318; }
    .status-healthy .status-dot { background: #12b76a; }
    .status-warning .status-dot { background: #f79009; }
    .status-critical .status-dot { background: #f04438; }
    .enterprise-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
    .enterprise-card { padding: 18px; display: flex; flex-direction: column; min-height: 236px; }
    .enterprise-card h3 { font-size: 1.02rem; font-weight: 800; margin-bottom: 8px; color: #101828; }
    .enterprise-card p, .enterprise-card li { color: #667085; font-size: .92rem; }
    .enterprise-card ul { padding-left: 18px; margin-bottom: 14px; }
    .enterprise-card form { margin-top: auto; }
    .progress.slim { height: 8px; }
    .wizard-panel { padding: 20px; }
    .wizard-steps { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 18px; }
    .wizard-step { border: 1px solid #e4e7ec; border-radius: 8px; padding: 10px; color: #475467; background: #f9fafb; min-height: 72px; }
    .wizard-step strong { display: block; color: #101828; font-size: .9rem; }
    .health-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
    .health-panel { padding: 15px; }
    .health-panel i { color: #175cd3; }
    .maintenance-dashboard { display: grid; grid-template-columns: 1.25fr .75fr; gap: 16px; margin-bottom: 24px; }
    .dashboard-panel { background: #fff; border: 1px solid #e4e7ec; border-radius: 8px; padding: 18px; box-shadow: 0 12px 28px rgba(16, 24, 40, .06); }
    .dashboard-panel h2 { font-size: 1.08rem; font-weight: 850; margin: 0 0 14px; color: #101828; }
    .analytics-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .analytics-tile { border: 1px solid #edf0f3; border-radius: 8px; padding: 14px; background: #f9fafb; min-height: 118px; }
    .analytics-tile span { display: block; color: #667085; font-size: .78rem; font-weight: 800; text-transform: uppercase; }
    .analytics-tile strong { display: block; color: #101828; font-size: 1.35rem; margin: 8px 0 6px; }
    .cost-layout { display: grid; grid-template-columns: .9fr 1.1fr; gap: 16px; }
    .cost-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
    .cost-card { border: 1px solid #e4e7ec; border-radius: 8px; padding: 15px; background: #f9fafb; }
    .cost-card span { display: block; color: #667085; font-size: .78rem; font-weight: 800; text-transform: uppercase; }
    .cost-card strong { color: #101828; font-size: 1.55rem; }
    .cost-bar { display: grid; grid-template-columns: 120px 1fr 86px; gap: 10px; align-items: center; margin-bottom: 10px; color: #475467; font-size: .9rem; }
    .bar-track { height: 10px; border-radius: 999px; background: #eef2f6; overflow: hidden; }
    .bar-fill { height: 100%; border-radius: inherit; background: #175cd3; }
    .alert-list { display: grid; gap: 10px; }
    .alert-item { display: flex; gap: 10px; align-items: flex-start; border: 1px solid #fedf89; border-radius: 8px; padding: 11px; background: #fffbeb; color: #7a4b00; }
    .alert-item.ok { border-color: #abefc6; background: #ecfdf3; color: #027a48; }
    .form-section-title { color: #101828; font-weight: 850; margin: 0 0 12px; }
    .backup-table td, .backup-table th { vertical-align: middle; }
    .coverage-list { columns: 2; }
    @media (max-width: 1100px) {
        .metric-grid, .enterprise-grid, .health-grid, .analytics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .maintenance-dashboard, .cost-layout { grid-template-columns: 1fr; }
        .recovery-hero { grid-template-columns: 1fr; }
        .hero-actions { justify-content: flex-start; }
        .wizard-steps { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
        .metric-grid, .enterprise-grid, .health-grid, .wizard-steps, .analytics-grid, .cost-summary { grid-template-columns: 1fr; }
        .recovery-hero { padding: 20px; }
        .coverage-list { columns: 1; }
        .cost-bar { grid-template-columns: 1fr; }
    }
</style>

<div class="container-fluid mt-4 recovery-center">
    <?php include '../includes/breadcrumb.php'; ?>
    <?php include '../includes/back_button.php'; ?>

    <section class="recovery-hero mb-3">
        <div>
            <h1><i class="fas fa-shield-halved"></i> Backup, Recovery & Maintenance Center</h1>
            <p>Complete protection for sacramental records, user accounts, transactions, uploaded documents, system configuration, application files, logs, and recovery metadata.</p>
        </div>
        <div class="hero-actions">
            <form method="POST">
                <input type="hidden" name="action" value="full_backup">
                <button type="submit" class="btn btn-primary" data-progress-button>
                    <i class="fas fa-box-archive"></i> Complete System Backup
                </button>
            </form>
            <a href="#recoveryWizard" class="btn btn-outline-primary"><i class="fas fa-life-ring"></i> Emergency Recovery</a>
        </div>
    </section>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="metric-grid">
        <div class="metric-card">
            <div class="metric-label">Total Backups</div>
            <div class="metric-value"><?php echo count($backup_files); ?></div>
            <div class="metric-note">Protected recovery files</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Last Backup</div>
            <div class="metric-value"><?php echo $latest_backup ? date('M d, Y', $latest_backup) : 'None'; ?></div>
            <div class="metric-note"><?php echo $latest_backup ? date('g:i A', $latest_backup) : 'Create one now'; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Next Scheduled</div>
            <div class="metric-value"><?php echo $scheduler_enabled ? 'Daily ' . e($daily_time) : 'Paused'; ?></div>
            <div class="metric-note">Weekly <?php echo e($weekly_day); ?>, monthly day <?php echo e($monthly_day); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Storage Usage</div>
            <div class="metric-value"><?php echo formatFileSize($total_backup_size); ?></div>
            <div class="metric-note">Local backup folder</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Recovery Readiness</div>
            <div class="metric-value">
                <span class="status-pill status-<?php echo e($recovery_readiness); ?>"><span class="status-dot"></span><?php echo e(healthLabel($recovery_readiness)); ?></span>
            </div>
            <div class="metric-note">Backup age and ZIP support</div>
        </div>
    </div>

    <div class="health-grid mb-4">
        <div class="health-panel"><i class="fas fa-database"></i> Database Health<br><span class="status-pill status-<?php echo e($db_status); ?>"><span class="status-dot"></span><?php echo e(healthLabel($db_status)); ?></span></div>
        <div class="health-panel"><i class="fas fa-hard-drive"></i> Storage Capacity<br><span class="status-pill status-<?php echo e($storage_status); ?>"><span class="status-dot"></span><?php echo e(healthLabel($storage_status)); ?></span></div>
        <div class="health-panel"><i class="fas fa-microchip"></i> Server Performance<br><span class="status-pill status-healthy"><span class="status-dot"></span>Healthy</span></div>
        <div class="health-panel"><i class="fas fa-user-shield"></i> Security Status<br><span class="status-pill status-healthy"><span class="status-dot"></span>Healthy</span></div>
        <div class="health-panel"><i class="fas fa-file-shield"></i> Backup Integrity<br><span class="status-pill status-<?php echo e($backup_status); ?>"><span class="status-dot"></span><?php echo e(healthLabel($backup_status)); ?></span></div>
    </div>

    <section class="maintenance-dashboard">
        <div class="dashboard-panel">
            <h2><i class="fas fa-gauge-high"></i> Maintenance Dashboard</h2>
            <div class="analytics-grid">
                <div class="analytics-tile">
                    <span>Backup Success Rate</span>
                    <strong><?php echo intval($backup_success_rate); ?>%</strong>
                    <div class="progress slim"><div class="progress-bar bg-success" style="width: <?php echo intval($backup_success_rate); ?>%"></div></div>
                </div>
                <div class="analytics-tile">
                    <span>Database Storage</span>
                    <strong><?php echo e(formatFileSize($db_storage_bytes)); ?></strong>
                    <div class="metric-note">Current schema size</div>
                </div>
                <div class="analytics-tile">
                    <span>Backup Storage Used</span>
                    <strong><?php echo intval($backup_storage_percent); ?>%</strong>
                    <div class="progress slim"><div class="progress-bar bg-<?php echo $backup_storage_percent >= 85 ? 'warning' : 'primary'; ?>" style="width: <?php echo intval($backup_storage_percent); ?>%"></div></div>
                </div>
                <div class="analytics-tile">
                    <span>Server Memory Peak</span>
                    <strong><?php echo e(formatFileSize($memory_peak)); ?></strong>
                    <div class="metric-note">PHP runtime usage</div>
                </div>
                <div class="analytics-tile">
                    <span>Maintenance Runs</span>
                    <strong><?php echo count($recent_maintenance_logs); ?></strong>
                    <div class="metric-note">Recent logged actions</div>
                </div>
                <div class="analytics-tile">
                    <span>Recovery Events</span>
                    <strong><?php echo count($recent_recovery_logs); ?></strong>
                    <div class="metric-note">Recent recovery history</div>
                </div>
            </div>
        </div>
        <div class="dashboard-panel">
            <h2><i class="fas fa-triangle-exclamation"></i> Critical Alerts</h2>
            <div class="alert-list">
                <?php if ($critical_alerts): ?>
                    <?php foreach ($critical_alerts as $alert): ?>
                        <div class="alert-item"><i class="fas fa-circle-exclamation"></i><span><?php echo e($alert); ?></span></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert-item ok"><i class="fas fa-circle-check"></i><span>No critical maintenance alerts at this time.</span></div>
                <?php endif; ?>
                <div class="alert-item ok"><i class="fas fa-clock-rotate-left"></i><span>Recent maintenance and recovery actions are recorded with timestamps for audit review.</span></div>
            </div>
        </div>
    </section>

    <div class="enterprise-grid mb-4">
        <div class="enterprise-card">
            <h3><i class="fas fa-cloud-arrow-down"></i> Complete System Backup</h3>
            <p>Builds a disaster recovery package with the full database, uploads, source code, assets, templates, configuration, and recovery manifest.</p>
            <ul class="coverage-list">
                <?php foreach (backupCoverageItems() as $group => $items): ?>
                    <li><strong><?php echo e($group); ?>:</strong> <?php echo e(implode(', ', $items)); ?></li>
                <?php endforeach; ?>
            </ul>
            <form method="POST">
                <input type="hidden" name="action" value="full_backup">
                <button type="submit" class="btn btn-primary w-100" data-progress-button><i class="fas fa-file-zipper"></i> Create Recovery Package</button>
            </form>
        </div>

        <div class="enterprise-card">
            <h3><i class="fas fa-rotate-left"></i> Full System Recovery</h3>
            <p>Validates backup integrity, checks database structure, detects corrupt packages, and restores selected recovery scopes.</p>
            <ul>
                <li>Restore full database and files</li>
                <li>Restore sacramental, user, document, or announcement scope</li>
                <li>Record administrator, status, file count, and recovery type</li>
            </ul>
            <a href="#recoveryWizard" class="btn btn-outline-primary w-100"><i class="fas fa-wand-magic-sparkles"></i> Open Recovery Wizard</a>
        </div>

        <div class="enterprise-card">
            <h3><i class="fas fa-calendar-check"></i> Automated Backup Schedule</h3>
            <form method="POST">
                <input type="hidden" name="action" value="save_schedule">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="scheduler_enabled" id="schedulerEnabled" <?php echo $scheduler_enabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="schedulerEnabled">Enable scheduled backups</label>
                </div>
                <label class="form-label">Daily backup time</label>
                <input class="form-control mb-2" type="time" name="daily_backup_time" value="<?php echo e($daily_time); ?>">
                <label class="form-label">Weekly backup day</label>
                <select class="form-select mb-2" name="weekly_backup_day">
                    <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day): ?>
                        <option value="<?php echo e($day); ?>" <?php echo $weekly_day === $day ? 'selected' : ''; ?>><?php echo e($day); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label">Monthly backup day</label>
                <input class="form-control mb-3" type="number" name="monthly_backup_day" min="1" max="28" value="<?php echo e($monthly_day); ?>">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Save Schedule</button>
            </form>
        </div>

        <div class="enterprise-card">
            <h3><i class="fas fa-screwdriver-wrench"></i> Monthly Maintenance</h3>
            <p>Runs database repair and optimization, removes expired OTP records, reviews temporary files, validates backups, and applies retention rules.</p>
            <ul>
                <li>Daily backups retained for 30 days</li>
                <li>Weekly backups retained for 6 months</li>
                <li>Monthly backups retained for 2 years</li>
            </ul>
            <form method="POST">
                <input type="hidden" name="action" value="run_maintenance">
                <button type="submit" class="btn btn-warning w-100" data-confirm="Run monthly maintenance now?" data-progress-button><i class="fas fa-broom"></i> Run Maintenance</button>
            </form>
        </div>

        <div class="enterprise-card">
            <h3><i class="fas fa-chart-line"></i> System Health Monitor</h3>
            <p>Monitors database availability, local storage usage, ZIP recovery support, security posture, and backup freshness.</p>
            <div class="progress slim mb-2"><div class="progress-bar bg-<?php echo e(statusClass($recovery_readiness)); ?>" style="width: <?php echo $recovery_readiness === 'healthy' ? 94 : ($recovery_readiness === 'warning' ? 64 : 32); ?>%"></div></div>
            <p class="mb-0">Readiness score reflects current backup age and required recovery extensions.</p>
        </div>

        <div class="enterprise-card">
            <h3><i class="fas fa-clipboard-list"></i> Backup & Recovery Logs</h3>
            <p>Maintains recovery and maintenance activity history for continuity audits.</p>
            <ul>
                <li>Recovery date and time</li>
                <li>Administrator responsible</li>
                <li>Recovery type and status</li>
                <li>Files restored and operation details</li>
            </ul>
            <a href="#logs" class="btn btn-outline-secondary w-100"><i class="fas fa-list"></i> View Logs</a>
        </div>
    </div>

    <section class="dashboard-panel mb-4" id="operationalCosts">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h2><i class="fas fa-server"></i> Hosting and Operational Cost Monitoring</h2>
                <p class="text-muted mb-0">Document deployment requirements, server specifications, domain and SSL status, subscriptions, and projected operating costs.</p>
            </div>
            <div class="status-pill status-<?php echo $monthly_total > 0 ? 'healthy' : 'warning'; ?>">
                <span class="status-dot"></span><?php echo $monthly_total > 0 ? 'Cost Plan Documented' : 'Localhost / Pending Cost Plan'; ?>
            </div>
        </div>

        <div class="cost-layout">
            <div>
                <div class="cost-summary">
                    <div class="cost-card">
                        <span>Estimated Monthly</span>
                        <strong>PHP <?php echo number_format($monthly_total, 2); ?></strong>
                    </div>
                    <div class="cost-card">
                        <span>Estimated Annual</span>
                        <strong>PHP <?php echo number_format($annual_total, 2); ?></strong>
                    </div>
                </div>

                <?php foreach ($costs as $label => $amount): ?>
                    <div class="cost-bar">
                        <strong><?php echo e($label); ?></strong>
                        <div class="bar-track"><div class="bar-fill" style="width: <?php echo intval(round(($amount / $max_cost) * 100)); ?>%"></div></div>
                        <span>PHP <?php echo number_format($amount, 2); ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="row g-2 mt-3">
                    <div class="col-md-6"><strong>Provider:</strong> <?php echo e($hosting_provider); ?></div>
                    <div class="col-md-6"><strong>Plan:</strong> <?php echo e($hosting_plan); ?></div>
                    <div class="col-md-6"><strong>Domain:</strong> <?php echo e($domain_name); ?></div>
                    <div class="col-md-6"><strong>SSL:</strong> <?php echo e($ssl_status); ?></div>
                    <div class="col-12"><strong>Server Specs:</strong> <?php echo e($server_specs); ?></div>
                    <div class="col-12"><strong>Backup Location:</strong> <?php echo e($backup_location); ?></div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_operational_costs">
                <h3 class="form-section-title">Operational Documentation</h3>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Hosting Provider</label>
                        <input class="form-control" name="hosting_provider" value="<?php echo e($hosting_provider); ?>" placeholder="Example: Hostinger, AWS, local server">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hosting Plan</label>
                        <input class="form-control" name="hosting_plan" value="<?php echo e($hosting_plan); ?>" placeholder="Example: VPS 2GB RAM">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Server Specifications</label>
                        <input class="form-control" name="server_specs" value="<?php echo e($server_specs); ?>" placeholder="CPU, RAM, storage, PHP/MySQL versions">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Domain Name</label>
                        <input class="form-control" name="domain_name" value="<?php echo e($domain_name); ?>" placeholder="example.org">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SSL Certificate Status</label>
                        <input class="form-control" name="ssl_status" value="<?php echo e($ssl_status); ?>" placeholder="Active, pending, included with hosting">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Backup Location</label>
                        <input class="form-control" name="backup_location" value="<?php echo e($backup_location); ?>" placeholder="Local, external drive, cloud storage">
                    </div>
                </div>

                <h3 class="form-section-title mt-3">Monthly Cost Estimates</h3>
                <div class="row g-2">
                    <?php
                    $cost_inputs = [
                        'hosting_monthly' => 'Hosting',
                        'domain_monthly' => 'Domain',
                        'ssl_monthly' => 'SSL',
                        'sms_monthly' => 'SMS Gateway',
                        'email_monthly' => 'Email Service',
                        'backup_monthly' => 'Backup Storage',
                        'maintenance_monthly' => 'Maintenance'
                    ];
                    ?>
                    <?php foreach ($cost_inputs as $field => $label): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo e($label); ?> Cost</label>
                            <input class="form-control" type="number" min="0" step="0.01" name="<?php echo e($field); ?>" value="<?php echo e(costValue($conn, $field, '0.00')); ?>">
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12">
                        <label class="form-label">Subscription and Hosting Notes</label>
                        <textarea class="form-control" name="subscription_notes" rows="3"><?php echo e($subscription_notes); ?></textarea>
                    </div>
                </div>
                <button class="btn btn-primary mt-3 w-100" type="submit"><i class="fas fa-save"></i> Save Hosting and Cost Plan</button>
            </form>
        </div>
    </section>

    <section class="wizard-panel mb-4" id="recoveryWizard">
        <h2 class="h4 mb-3"><i class="fas fa-life-ring"></i> Emergency Recovery Mode</h2>
        <div class="wizard-steps">
            <div class="wizard-step"><strong>Step 1</strong>Upload or select a recovery package.</div>
            <div class="wizard-step"><strong>Step 2</strong>Validate backup integrity and structure.</div>
            <div class="wizard-step"><strong>Step 3</strong>Choose the recovery scope.</div>
            <div class="wizard-step"><strong>Step 4</strong>Execute confirmed recovery.</div>
            <div class="wizard-step"><strong>Step 5</strong>Review logs and verification result.</div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="validate_backup">
                    <label class="form-label">Upload Recovery Package</label>
                    <input type="file" name="recovery_package" class="form-control mb-2" accept=".zip,.sql">
                    <label class="form-label">Or validate existing backup</label>
                    <select name="backup_file" class="form-select mb-3">
                        <option value="">Select backup file</option>
                        <?php foreach ($backup_files as $file): ?>
                            <option value="<?php echo e(basename($file)); ?>"><?php echo e(basename($file)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-magnifying-glass-chart"></i> Validate Backup</button>
                </form>
            </div>
            <div class="col-lg-6">
                <form method="POST" id="restoreForm">
                    <input type="hidden" name="action" value="restore_backup">
                    <label class="form-label">Recovery Package</label>
                    <select name="backup_file" class="form-select mb-2" required>
                        <?php foreach ($backup_files as $file): ?>
                            <option value="<?php echo e(basename($file)); ?>"><?php echo e(basename($file)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label">Recovery Scope</label>
                    <select name="recovery_scope" class="form-select mb-2" required>
                        <?php foreach (recoveryScopes() as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label">Confirmation</label>
                    <input type="text" name="confirmation" class="form-control mb-3" placeholder="Type RESTORE to confirm" required>
                    <button type="submit" class="btn btn-danger" data-confirm="This will overwrite the selected recovery scope. Continue?" data-progress-button>
                        <i class="fas fa-triangle-exclamation"></i> Execute Recovery
                    </button>
                </form>
            </div>
        </div>

        <?php if ($validation_result): ?>
            <div class="alert alert-<?php echo $validation_result['valid'] ? 'success' : 'danger'; ?> mt-3">
                <strong><?php echo e($validation_result['file']); ?></strong>
                <div class="row mt-2">
                    <?php foreach ($validation_result['checks'] as $check): ?>
                        <div class="col-md-6 mb-2">
                            <span class="status-pill status-<?php echo e($check['status']); ?>"><span class="status-dot"></span><?php echo e($check['text']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($validation_result['manifest']['tables'])): ?>
                    <div class="mt-2">Tables in manifest: <?php echo count($validation_result['manifest']['tables']); ?>. Included files: <?php echo intval($validation_result['manifest']['included_file_count'] ?? 0); ?>.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0"><i class="fas fa-clock-rotate-left"></i> Available Backup Files</h2>
                <div class="d-flex flex-wrap gap-2">
                    <form method="POST">
                        <input type="hidden" name="action" value="database_backup">
                        <button type="submit" class="btn btn-sm btn-outline-primary" data-progress-button><i class="fas fa-database"></i> Daily DB Backup</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="action" value="weekly_backup">
                        <button type="submit" class="btn btn-sm btn-outline-success" data-progress-button><i class="fas fa-folder-tree"></i> Weekly Backup</button>
                    </form>
                </div>
            </div>

            <?php if (count($backup_files) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover backup-table">
                        <thead class="table-light">
                            <tr>
                                <th>File</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th>Integrity</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backup_files as $file): ?>
                                <?php $quick_validation = validateBackupPackage($file); ?>
                                <tr>
                                    <td><strong><?php echo e(basename($file)); ?></strong></td>
                                    <td><?php echo strtoupper(e(pathinfo($file, PATHINFO_EXTENSION))); ?></td>
                                    <td><?php echo e(formatFileSize(filesize($file))); ?></td>
                                    <td><?php echo date('M d, Y g:i A', filemtime($file)); ?></td>
                                    <td><span class="status-pill status-<?php echo $quick_validation['valid'] ? 'healthy' : 'warning'; ?>"><span class="status-dot"></span><?php echo $quick_validation['valid'] ? 'Valid' : 'Review'; ?></span></td>
                                    <td>
                                        <a href="?download=<?php echo urlencode(basename($file)); ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">No backup files created yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4 mb-4" id="logs">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3"><i class="fas fa-rotate-left"></i> Recovery Logs</h2>
                    <?php if ($recent_recovery_logs): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>Date</th><th>Admin/User</th><th>Type</th><th>File</th><th>Status</th><th>Files</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recent_recovery_logs as $log): ?>
                                        <tr>
                                            <td><?php echo e(formatDateTime($log['created_at'])); ?></td>
                                            <td><?php echo e(maintenanceAdminName($conn, $log['admin_id'] ?? 0)); ?></td>
                                            <td><?php echo e($log['recovery_type']); ?></td>
                                            <td><?php echo e($log['backup_file']); ?></td>
                                            <td><?php echo e($log['status']); ?></td>
                                            <td><?php echo intval($log['files_restored']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border mb-0">No recovery operations logged yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3"><i class="fas fa-screwdriver-wrench"></i> Maintenance Logs</h2>
                    <?php if ($recent_maintenance_logs): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>Date</th><th>Admin/User</th><th>Type</th><th>Status</th><th>Details</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recent_maintenance_logs as $log): ?>
                                        <tr>
                                            <td><?php echo e(formatDateTime($log['created_at'])); ?></td>
                                            <td><?php echo e(maintenanceAdminName($conn, $log['admin_id'] ?? 0)); ?></td>
                                            <td><?php echo e($log['maintenance_type']); ?></td>
                                            <td><?php echo e($log['status']); ?></td>
                                            <td><?php echo e(substr($log['details'] ?? '', 0, 120)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border mb-0">No maintenance runs logged yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning">
        <strong>Continuity reminder:</strong> download complete recovery packages and store copies outside this server. A local backup cannot protect the parish if the entire computer or XAMPP folder is lost.
    </div>
</div>

<script>
    document.querySelectorAll('[data-confirm]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            if (!confirm(button.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-progress-button]').forEach(function(button) {
        button.closest('form')?.addEventListener('submit', function() {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        });
    });
</script>

<?php include '../templates/footer.php'; ?>
