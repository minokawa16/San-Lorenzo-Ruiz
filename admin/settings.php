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

$page_title = 'Settings & Parish Backup';
$error = '';
$success = '';
$validation_result = null;
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
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tugon_backups',
        '/tmp/tugon_backups'
    ];

    foreach ($candidates as $candidate) {
        if (ensureBackupDirectory($candidate)) {
            return $candidate;
        }
    }

    return $project_root . DIRECTORY_SEPARATOR . 'backups';
}

$backup_dir = resolveBackupDirectory();

// Recovery Schema - Creates logs and settings tables used by backup and maintenance tools.
function ensureRecoverySchema($conn) {
    return requireSchemaTables($conn, [
        'recovery_logs', 'maintenance_logs', 'system_settings'
    ], 'backup and recovery');
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
            'first_communion_records', 'certificate_templates', 'certificate_file_templates',
            'certificate_layouts', 'certificate_issuances', 'requests', 'request_documents',
            'request_payments'
        ],
        'user_accounts' => [
            'users', 'audit_log', 'notification_preferences', 'email_verifications',
            'otp_codes', 'notification_logs', 'sms_notification_logs'
        ],
        'announcements' => [
            'announcements', 'announcement_recipients', 'notification_logs', 'notifications'
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

// Reliable write test probe that bypasses flaky Windows/NTFS is_writable() checks
function isDirectoryReallyWritable($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    if (@is_writable($dir)) {
        return true;
    }
    $test_file = $dir . DIRECTORY_SEPARATOR . '.probe_' . uniqid('', true) . '.tmp';
    $handle = @fopen($test_file, 'wb');
    if ($handle !== false) {
        @fwrite($handle, '1');
        @fclose($handle);
        @unlink($test_file);
        return true;
    }
    return false;
}

// Backup Storage - Ensures backup folders are writable and protected from direct browsing.
function ensureBackupDirectory($backup_dir) {
    if (empty($backup_dir)) {
        return false;
    }

    if (is_link($backup_dir)) {
        $target = @readlink($backup_dir);
        if ($target && !is_dir($target)) {
            @mkdir($target, 0777, true);
        }
    }

    if (!is_dir($backup_dir)) {
        if (!@mkdir($backup_dir, 0777, true) && !is_dir($backup_dir)) {
            return false;
        }
    }

    @chmod($backup_dir, 0777);

    $index_file = $backup_dir . DIRECTORY_SEPARATOR . 'index.php';
    if (!file_exists($index_file)) {
        @file_put_contents($index_file, "<?php\nhttp_response_code(403);\nexit('Access denied');\n");
    }

    $htaccess_file = $backup_dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess_file)) {
        @file_put_contents($htaccess_file, "Options -Indexes\nRequire all denied\nDeny from all\n");
    }

    return isDirectoryReallyWritable($backup_dir);
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
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    if (!ensureBackupDirectory($backup_dir)) {
        throw new Exception('Backup folder is not writable. Attempted path: ' . $backup_dir);
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
        'system_version' => '2.5.0',
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
    $included_dirs = ['admin', 'api', 'assets', 'auth', 'config', 'database', 'includes', 'logs', 'ocr', 'services', 'templates', 'uploads', 'users', 'views'];
    $backup_real = realpath($backup_dir);
    $root_length = strlen($project_root) + 1;

    foreach ($included_dirs as $dirName) {
        $dirPath = $project_root . DIRECTORY_SEPARATOR . $dirName;
        if (!is_dir($dirPath)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS),
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
            } elseif ($file->isFile() && $file->getSize() < 50 * 1024 * 1024) {
                $zip->addFile($path, $relative_path);
            }
        }
    }

    // Add root php files
    $rootFiles = glob($project_root . DIRECTORY_SEPARATOR . '*.php') ?: [];
    foreach ($rootFiles as $rf) {
        if (is_file($rf)) {
            $zip->addFile($rf, basename($rf));
        }
    }
}

// Create Full Backup Function - Documents this helper's role in the parish management workflow.
function createFullBackup($conn, $backup_dir, $project_root, $backup_type = 'complete-system') {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive is not enabled. Enable the zip extension in php.ini / XAMPP to create full recovery packages.');
    }

    if (!ensureBackupDirectory($backup_dir)) {
        throw new Exception('Backup folder is not writable. Attempted path: ' . $backup_dir);
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
    $search_dirs = array_unique(array_filter([
        $backup_dir,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups',
        '/var/www/tugon-data/backups',
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tugon_backups',
        '/tmp/tugon_backups'
    ]));

    $files = [];
    foreach ($search_dirs as $dir) {
        if (is_dir($dir)) {
            $matched = glob($dir . DIRECTORY_SEPARATOR . '*.{sql,zip,csv}', GLOB_BRACE) ?: [];
            foreach ($matched as $f) {
                if (is_file($f)) {
                    $files[basename($f)] = $f;
                }
            }
        }
    }

    $file_list = array_values($files);
    usort($file_list, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    return $file_list;
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
        throw new Exception('Backup folder is not writable. Attempted path: ' . $backup_dir);
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

// Export Parish Records - Generates clean, Excel-compatible CSV spreadsheets for selected parish record categories
function exportParishRecords($conn, array $categories, $backup_dir, $user_id = 0) {
    if (empty($categories)) {
        throw new Exception('Please select at least one record category to back up.');
    }

    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    if (!ensureBackupDirectory($backup_dir)) {
        throw new Exception('Backup folder is not writable.');
    }

    $timestamp = date('Y-m-d_His');
    $temp_dir = $backup_dir . DIRECTORY_SEPARATOR . 'temp_export_' . uniqid('', true);
    if (!@mkdir($temp_dir, 0777, true) && !is_dir($temp_dir)) {
        throw new Exception('Unable to create temporary export folder.');
    }

    $files_created = [];

    // Columns to exclude for user privacy and security
    $sensitive_user_cols = [
        'password', 'salt', 'remember_token', 'token', 'reset_token',
        'otp_code', 'otp_expiry', 'otp_verified', 'auth_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'failed_login_attempts',
        'lockout_time', 'email_verification_token', 'verification_token'
    ];

    $category_map = [
        'parishioners' => [
            ['table' => 'users', 'filename' => 'Parishioner_Directory.csv', 'sensitive' => $sensitive_user_cols]
        ],
        'sacramental' => [
            ['table' => 'baptism_records', 'filename' => 'Sacramental_Baptism_Records.csv', 'sensitive' => []],
            ['table' => 'confirmation_records', 'filename' => 'Sacramental_Confirmation_Records.csv', 'sensitive' => []],
            ['table' => 'marriage_records', 'filename' => 'Sacramental_Marriage_Records.csv', 'sensitive' => []],
            ['table' => 'first_communion_records', 'filename' => 'Sacramental_First_Communion_Records.csv', 'sensitive' => []]
        ],
        'funeral' => [
            ['table' => 'funeral_records', 'filename' => 'Funeral_and_Burial_Records.csv', 'sensitive' => []]
        ],
        'requests' => [
            ['table' => 'requests', 'filename' => 'Certificate_Requests.csv', 'sensitive' => []]
        ],
        'reservations' => [
            ['table' => 'reservations', 'filename' => 'Church_Reservations.csv', 'sensitive' => []]
        ]
    ];

    foreach ($categories as $cat) {
        if (!isset($category_map[$cat])) {
            continue;
        }

        foreach ($category_map[$cat] as $item) {
            $table = $item['table'];
            $filename = $item['filename'];
            $omit = $item['sensitive'];

            $tbl_check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
            if (!$tbl_check || $tbl_check->num_rows === 0) {
                continue;
            }

            $cols_res = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
            $columns = [];
            while ($cols_res && $c = $cols_res->fetch_assoc()) {
                if (!in_array(strtolower($c['Field']), $omit, true)) {
                    $columns[] = $c['Field'];
                }
            }

            if (empty($columns)) {
                continue;
            }

            $col_sql = '`' . implode('`, `', array_map(function($c) { return str_replace('`', '``', $c); }, $columns)) . '`';
            $data_res = $conn->query("SELECT $col_sql FROM `" . str_replace('`', '``', $table) . "`");

            $file_path = $temp_dir . DIRECTORY_SEPARATOR . $filename;
            $fp = fopen($file_path, 'wb');
            if (!$fp) {
                continue;
            }

            // UTF-8 BOM for seamless Microsoft Excel compatibility
            fwrite($fp, "\xEF\xBB\xBF");

            $headers = array_map(function($c) {
                return ucwords(str_replace('_', ' ', $c));
            }, $columns);
            fputcsv($fp, $headers);

            if ($data_res) {
                while ($row = $data_res->fetch_assoc()) {
                    fputcsv($fp, array_values($row));
                }
            }

            fclose($fp);
            $files_created[] = $file_path;
        }
    }

    if (empty($files_created)) {
        @rmdir($temp_dir);
        throw new Exception('No parish records found for the selected categories.');
    }

    $zip_filename = 'Parish_Records_Backup_' . $timestamp . '.zip';
    $zip_path = $backup_dir . DIRECTORY_SEPARATOR . $zip_filename;

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files_created as $f) {
                $zip->addFile($f, basename($f));
            }
            $zip->close();

            foreach ($files_created as $f) {
                @unlink($f);
            }
            @rmdir($temp_dir);

            if ($user_id > 0 && function_exists('createAuditLog')) {
                createAuditLog($conn, $user_id, 'DOWNLOAD_PARISH_RECORDS_BACKUP', 'system', 0, null, [
                    'filename' => $zip_filename,
                    'categories' => $categories
                ]);
            }

            return $zip_path;
        }
    }

    if (count($files_created) === 1) {
        $single_path = $backup_dir . DIRECTORY_SEPARATOR . 'Parish_Records_' . basename($files_created[0]);
        rename($files_created[0], $single_path);
        @rmdir($temp_dir);
        return $single_path;
    }

    throw new Exception('Unable to generate ZIP archive of parish records.');
}

// Direct Stream Action: Immediately generates and streams complete system backup or DB snapshot to the browser
if (isset($_GET['download_action']) && in_array($_GET['download_action'], ['full_backup', 'database_backup', 'weekly_backup'], true)) {
    requireAdmin();
    requirePermission('system.settings');
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    try {
        if ($_GET['download_action'] === 'full_backup' || $_GET['download_action'] === 'weekly_backup') {
            $path = createFullBackup($conn, $backup_dir, $project_root, 'complete-system');
            writeSetting($conn, 'last_monthly_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'] ?? 0, 'DOWNLOAD_COMPLETE_SYSTEM_BACKUP', 'system', 0);
        } else {
            $path = createDatabaseBackup($conn, $backup_dir, 'database-backup');
            writeSetting($conn, 'last_daily_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'] ?? 0, 'DOWNLOAD_DATABASE_BACKUP', 'system', 0);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime_type = $extension === 'zip' ? 'application/zip' : 'application/sql';

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($path));

        readfile($path);
        exit;
    } catch (Exception $e) {
        $error = 'Failed to generate backup: ' . $e->getMessage();
    }
}

if (isset($_GET['download'])) {
    $requested = basename($_GET['download']);
    $search_dirs = array_unique(array_filter([
        $backup_dir,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups',
        '/var/www/tugon-data/backups',
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tugon_backups',
        '/tmp/tugon_backups'
    ]));

    $path = null;
    foreach ($search_dirs as $dir) {
        $candidate = $dir . DIRECTORY_SEPARATOR . $requested;
        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }

    // Serverless Fallback: If running on ephemeral environments (e.g. Vercel) and file isn't in current container
    if ((!$path || !is_file($path)) && $requested !== '') {
        try {
            if (strpos($requested, 'complete-system') !== false || strpos($requested, 'recovery') !== false || pathinfo($requested, PATHINFO_EXTENSION) === 'zip') {
                $path = createFullBackup($conn, $backup_dir, $project_root, 'complete-system');
            } elseif (strpos($requested, 'database') !== false || pathinfo($requested, PATHINFO_EXTENSION) === 'sql') {
                $path = createDatabaseBackup($conn, $backup_dir, 'database-backup');
            }
        } catch (Exception $e) {
            $path = null;
        }
    }

    $extension = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

    if (!$path || !in_array($extension, ['sql', 'zip', 'csv'], true) || !is_file($path)) {
        http_response_code(404);
        exit('Backup file not found.');
    }

    $mime_type = $extension === 'zip' ? 'application/zip' : ($extension === 'csv' ? 'text/csv; charset=UTF-8' : 'application/sql');

    // Clear any previous output buffers to avoid corrupting binary data
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($path));

    $fp = fopen($path, 'rb');
    if ($fp !== false) {
        while (!feof($fp)) {
            echo fread($fp, 1024 * 1024);
            flush();
        }
        fclose($fp);
    } else {
        readfile($path);
    }
    exit;
}

$is_ajax_request = (isset($_POST['ajax']) && $_POST['ajax'] === '1') 
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'download_parish_backup') {
            $categories = $_POST['categories'] ?? [];
            if (!is_array($categories) || empty($categories)) {
                throw new Exception('Please select at least one record category to back up.');
            }

            $path = exportParishRecords($conn, $categories, $backup_dir, $_SESSION['user_id'] ?? 0);

            while (ob_get_level()) {
                ob_end_clean();
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime_type = $extension === 'zip' ? 'application/zip' : 'text/csv; charset=UTF-8';

            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . filesize($path));

            $fp = fopen($path, 'rb');
            if ($fp !== false) {
                while (!feof($fp)) {
                    echo fread($fp, 1024 * 1024);
                    flush();
                }
                fclose($fp);
            } else {
                readfile($path);
            }
            exit;
        } elseif ($action === 'database_backup') {
            $path = createDatabaseBackup($conn, $backup_dir, 'daily-database');
            writeSetting($conn, 'last_daily_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'], 'CREATE_DATABASE_BACKUP', 'system', 0);
            $success = 'Database backup created: ' . basename($path);
            
            if ($is_ajax_request) {
                $backup_files = getBackupFiles($backup_dir);
                $total_backup_size = directorySize($backup_dir);
                $latest_backup = latestBackupTime($backup_files);
                $backup_status = backupStatusFromAge($latest_backup, 7, 30);
                $zip_status = class_exists('ZipArchive') ? 'healthy' : 'critical';
                $recovery_readiness = ($backup_status === 'healthy' && $zip_status === 'healthy') ? 'healthy' : (($backup_status === 'critical' || $zip_status === 'critical') ? 'critical' : 'warning');

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $success,
                    'filename' => basename($path),
                    'filesize' => formatFileSize(filesize($path)),
                    'filesize_bytes' => filesize($path),
                    'created_at' => date('M d, Y g:i A', filemtime($path)),
                    'type' => 'SQL',
                    'download_url' => 'settings.php?download=' . urlencode(basename($path)),
                    'metrics' => [
                        'total_backups' => count($backup_files),
                        'latest_backup_text' => date('M d, Y', $latest_backup),
                        'latest_backup_subtext' => 'Just now',
                        'storage_usage_text' => formatFileSize($total_backup_size),
                        'recovery_readiness' => $recovery_readiness,
                        'recovery_readiness_label' => healthLabel($recovery_readiness),
                        'backup_integrity' => $backup_status,
                        'backup_integrity_label' => healthLabel($backup_status)
                    ]
                ]);
                exit;
            }
        } elseif ($action === 'weekly_backup') {
            $path = createFullBackup($conn, $backup_dir, $project_root, 'weekly-files');
            writeSetting($conn, 'last_weekly_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'], 'CREATE_WEEKLY_BACKUP', 'system', 0);
            $success = 'Weekly database and file backup created: ' . basename($path);

            if ($is_ajax_request) {
                $backup_files = getBackupFiles($backup_dir);
                $total_backup_size = directorySize($backup_dir);
                $latest_backup = latestBackupTime($backup_files);
                $backup_status = backupStatusFromAge($latest_backup, 7, 30);
                $zip_status = class_exists('ZipArchive') ? 'healthy' : 'critical';
                $recovery_readiness = ($backup_status === 'healthy' && $zip_status === 'healthy') ? 'healthy' : (($backup_status === 'critical' || $zip_status === 'critical') ? 'critical' : 'warning');

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $success,
                    'filename' => basename($path),
                    'filesize' => formatFileSize(filesize($path)),
                    'filesize_bytes' => filesize($path),
                    'created_at' => date('M d, Y g:i A', filemtime($path)),
                    'type' => 'ZIP',
                    'download_url' => 'settings.php?download=' . urlencode(basename($path)),
                    'metrics' => [
                        'total_backups' => count($backup_files),
                        'latest_backup_text' => date('M d, Y', $latest_backup),
                        'latest_backup_subtext' => 'Just now',
                        'storage_usage_text' => formatFileSize($total_backup_size),
                        'recovery_readiness' => $recovery_readiness,
                        'recovery_readiness_label' => healthLabel($recovery_readiness),
                        'backup_integrity' => $backup_status,
                        'backup_integrity_label' => healthLabel($backup_status)
                    ]
                ]);
                exit;
            }
        } elseif ($action === 'full_backup') {
            $path = createFullBackup($conn, $backup_dir, $project_root, 'monthly-complete-system');
            writeSetting($conn, 'last_monthly_backup', date('Y-m-d H:i:s'));
            createAuditLog($conn, $_SESSION['user_id'], 'CREATE_COMPLETE_SYSTEM_BACKUP', 'system', 0);
            $success = 'Complete system recovery package created: ' . basename($path);

            if ($is_ajax_request) {
                $backup_files = getBackupFiles($backup_dir);
                $total_backup_size = directorySize($backup_dir);
                $latest_backup = latestBackupTime($backup_files);
                $backup_status = backupStatusFromAge($latest_backup, 7, 30);
                $zip_status = class_exists('ZipArchive') ? 'healthy' : 'critical';
                $recovery_readiness = ($backup_status === 'healthy' && $zip_status === 'healthy') ? 'healthy' : (($backup_status === 'critical' || $zip_status === 'critical') ? 'critical' : 'warning');

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $success,
                    'filename' => basename($path),
                    'filesize' => formatFileSize(filesize($path)),
                    'filesize_bytes' => filesize($path),
                    'created_at' => date('M d, Y g:i A', filemtime($path)),
                    'type' => 'ZIP',
                    'download_url' => 'settings.php?download=' . urlencode(basename($path)),
                    'metrics' => [
                        'total_backups' => count($backup_files),
                        'latest_backup_text' => date('M d, Y', $latest_backup),
                        'latest_backup_subtext' => 'Just now',
                        'storage_usage_text' => formatFileSize($total_backup_size),
                        'recovery_readiness' => $recovery_readiness,
                        'recovery_readiness_label' => healthLabel($recovery_readiness),
                        'backup_integrity' => $backup_status,
                        'backup_integrity_label' => healthLabel($backup_status)
                    ]
                ]);
                exit;
            }
        } elseif ($action === 'save_schedule') {
            writeSetting($conn, 'backup_scheduler_enabled', isset($_POST['scheduler_enabled']) ? '1' : '0');
            writeSetting($conn, 'daily_backup_time', $_POST['daily_backup_time'] ?? '01:00');
            writeSetting($conn, 'weekly_backup_day', $_POST['weekly_backup_day'] ?? 'Sunday');
            writeSetting($conn, 'monthly_backup_day', $_POST['monthly_backup_day'] ?? '1');
            $success = 'Automated backup schedule settings saved.';
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

        if ($is_ajax_request) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $error
            ]);
            exit;
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $automated_messages = runDueAutomatedTasks($conn, $backup_dir, $project_root, $_SESSION['user_id'] ?? 0);
    if ($automated_messages) {
        $success = trim($success . ' ' . implode(' ', $automated_messages));
    }
}

$backup_files = getBackupFiles($backup_dir);
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Backup Records' => null
];
?>
<?php include '../templates/header.php'; ?>

<style>
    .backup-records-container {
        max-width: 1100px;
        margin: 0 auto;
    }
    .btn-backup-brand {
        background-color: #7a5214 !important;
        border-color: #7a5214 !important;
        color: #ffffff !important;
        padding: 0.65rem 1.75rem;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.2s ease-in-out;
    }
    .btn-backup-brand:hover,
    .btn-backup-brand:focus {
        background-color: #63410e !important;
        border-color: #63410e !important;
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(122, 82, 20, 0.28);
    }
    .btn-backup-brand:active {
        background-color: #4f3309 !important;
        border-color: #4f3309 !important;
        transform: translateY(1px);
    }
    .category-selection-card {
        border: 1.5px solid #e4e7ec;
        border-radius: 10px;
        padding: 16px 18px;
        transition: all 0.2s ease;
        background: #ffffff;
        cursor: pointer;
        user-select: none;
        height: 100%;
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }
    .category-selection-card:hover {
        border-color: #c99b42;
        background-color: #fdfbf7;
        box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    }
    .category-selection-card.selected {
        border-color: #7a5214;
        background-color: #fcf9f3;
    }
    .category-checkbox {
        width: 20px;
        height: 20px;
        margin-top: 3px;
        accent-color: #7a5214;
        cursor: pointer;
        flex-shrink: 0;
    }
    .category-icon {
        width: 42px;
        height: 42px;
        border-radius: 8px;
        background-color: #f4ede4;
        color: #7a5214;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }
    .category-content h4 {
        font-size: 0.98rem;
        font-weight: 700;
        color: #101828;
        margin: 0 0 3px 0;
    }
    .category-content p {
        font-size: 0.84rem;
        color: #667085;
        margin: 0;
        line-height: 1.35;
    }
    .quick-toggle-btn {
        font-size: 0.84rem;
        color: #7a5214;
        font-weight: 600;
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
        text-decoration: underline;
    }
    .quick-toggle-btn:hover {
        color: #543912;
    }
    .saved-files-card {
        border: 1px solid #e4e7ec;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 6px 18px rgba(16, 24, 40, 0.04);
    }
    .table-saved td {
        vertical-align: middle;
        font-size: 0.88rem;
        padding: 12px 16px;
    }
    .table-saved th {
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        background: #f8fafc;
        color: #475467;
        padding: 12px 16px;
    }
</style>

<div class="container-fluid px-0 backup-records-container">
    <?php
    $page_header_title = 'Settings & Parish Backup';
    $page_header_subtitle = 'Download a copy of your parish records for safekeeping.';
    $page_header_icon = 'fa-cloud-arrow-down';
    $show_back_button = true;
    $back_button_url = BASE_URL . 'admin/dashboard.php';
    include '../includes/page_header.php';
    ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 mb-4" role="alert">
            <i class="fas fa-circle-exclamation me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 mb-4" role="alert">
            <i class="fas fa-circle-check me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Simplified Backup Parish Records Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 pb-3 mb-4 border-bottom">
                <div class="d-flex align-items-center gap-3">
                    <div class="category-icon" style="width: 48px; height: 48px; font-size: 1.35rem;">
                        <i class="fas fa-folder-arrow-down"></i>
                    </div>
                    <div>
                        <h2 class="h5 font-weight-bold text-dark mb-1">Backup Parish Records</h2>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">Download a copy of your parish records for safekeeping.</p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="quick-toggle-btn" id="toggleSelectAllBtn">Select All</button>
                    <span class="text-muted">•</span>
                    <button type="button" class="quick-toggle-btn" id="toggleDeselectAllBtn">Deselect All</button>
                </div>
            </div>

            <form method="POST" id="backupForm">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="download_parish_backup">

                <div class="mb-4">
                    <label class="form-label fw-bold text-dark mb-2" style="font-size: 0.92rem;">
                        Select Records to Include in Backup
                    </label>
                    <div class="row g-3">
                        <!-- Parishioner Records -->
                        <div class="col-md-6 col-lg-4">
                            <label class="category-selection-card selected" for="cat_parishioners">
                                <input type="checkbox" name="categories[]" value="parishioners" id="cat_parishioners" class="category-checkbox" checked>
                                <div class="category-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="category-content">
                                    <h4>Parishioner Directory</h4>
                                    <p>Registered parishioners, contact info, status, and profile details</p>
                                </div>
                            </label>
                        </div>

                        <!-- Sacramental Records -->
                        <div class="col-md-6 col-lg-4">
                            <label class="category-selection-card selected" for="cat_sacramental">
                                <input type="checkbox" name="categories[]" value="sacramental" id="cat_sacramental" class="category-checkbox" checked>
                                <div class="category-icon">
                                    <i class="fas fa-church"></i>
                                </div>
                                <div class="category-content">
                                    <h4>Sacramental Records</h4>
                                    <p>Baptism, Confirmation, Marriage, and First Communion registers</p>
                                </div>
                            </label>
                        </div>

                        <!-- Funeral Records -->
                        <div class="col-md-6 col-lg-4">
                            <label class="category-selection-card selected" for="cat_funeral">
                                <input type="checkbox" name="categories[]" value="funeral" id="cat_funeral" class="category-checkbox" checked>
                                <div class="category-icon">
                                    <i class="fas fa-cross"></i>
                                </div>
                                <div class="category-content">
                                    <h4>Funeral &amp; Burial Records</h4>
                                    <p>Deceased parishioner records, burial dates, ministers, and resting places</p>
                                </div>
                            </label>
                        </div>

                        <!-- Certificate Requests -->
                        <div class="col-md-6 col-lg-4">
                            <label class="category-selection-card selected" for="cat_requests">
                                <input type="checkbox" name="categories[]" value="requests" id="cat_requests" class="category-checkbox" checked>
                                <div class="category-icon">
                                    <i class="fas fa-file-lines"></i>
                                </div>
                                <div class="category-content">
                                    <h4>Certificates &amp; Requests</h4>
                                    <p>Document applications, issuance tracking, and request records</p>
                                </div>
                            </label>
                        </div>

                        <!-- Church Reservations -->
                        <div class="col-md-6 col-lg-4">
                            <label class="category-selection-card selected" for="cat_reservations">
                                <input type="checkbox" name="categories[]" value="reservations" id="cat_reservations" class="category-checkbox" checked>
                                <div class="category-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="category-content">
                                    <h4>Church Reservations</h4>
                                    <p>Mass intentions, wedding bookings, blessings, and chapel schedules</p>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="bg-light rounded-3 p-3 mb-4 d-flex align-items-center gap-3 border">
                    <i class="fas fa-circle-info text-primary-brand fa-lg ms-1"></i>
                    <div style="font-size: 0.88rem;" class="text-secondary">
                        Records are prepared as standard spreadsheet files (CSV) compatible with Microsoft Excel and LibreOffice, packaged into a single convenient zip file.
                    </div>
                </div>

                <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 pt-2">
                    <button type="submit" id="downloadBackupBtn" class="btn btn-backup-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2">
                        <i class="fas fa-cloud-arrow-down"></i>
                        <span>Download Backup</span>
                    </button>
                    <span class="text-muted small">
                        <i class="fas fa-lock me-1"></i> Passwords and personal security credentials are automatically excluded for safety.
                    </span>
                </div>
            </form>
        </div>
    </div>

    <!-- Previously Saved Files -->
    <?php if (!empty($backup_files)): ?>
        <div class="card saved-files-card border-0 mb-4">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <h3 class="h6 mb-0 font-weight-bold text-dark d-flex align-items-center gap-2">
                    <i class="fas fa-clock-rotate-left text-muted"></i> Previously Saved Backups
                </h3>
                <span class="badge bg-light text-dark border"><?php echo count($backup_files); ?> file<?php echo count($backup_files) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-saved mb-0">
                        <thead>
                            <tr>
                                <th>Backup File</th>
                                <th>Date Created</th>
                                <th>File Size</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($backup_files, 0, 10) as $file): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="far fa-file-zipper text-muted fa-lg"></i>
                                            <span class="fw-semibold text-dark"><?php echo e(basename($file)); ?></span>
                                        </div>
                                    </td>
                                    <td class="text-muted"><?php echo date('M d, Y g:i A', filemtime($file)); ?></td>
                                    <td class="text-muted"><?php echo e(formatFileSize(filesize($file))); ?></td>
                                    <td class="text-end">
                                        <a href="settings.php?download=<?php echo urlencode(basename($file)); ?>" class="btn btn-sm btn-outline-secondary px-3">
                                            <i class="fas fa-download me-1"></i> Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var checkboxes = document.querySelectorAll('.category-checkbox');
        var form = document.getElementById('backupForm');
        var downloadBtn = document.getElementById('downloadBackupBtn');
        var selectAllBtn = document.getElementById('toggleSelectAllBtn');
        var deselectAllBtn = document.getElementById('toggleDeselectAllBtn');

        // Sync card active state with checkbox state
        checkboxes.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var card = cb.closest('.category-selection-card');
                if (card) {
                    if (cb.checked) {
                        card.classList.add('selected');
                    } else {
                        card.classList.remove('selected');
                    }
                }
            });
        });

        // Select All / Deselect All
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                checkboxes.forEach(function(cb) {
                    cb.checked = true;
                    var card = cb.closest('.category-selection-card');
                    if (card) card.classList.add('selected');
                });
            });
        }

        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function() {
                checkboxes.forEach(function(cb) {
                    cb.checked = false;
                    var card = cb.closest('.category-selection-card');
                    if (card) card.classList.remove('selected');
                });
            });
        }

        // Form submission feedback
        if (form && downloadBtn) {
            form.addEventListener('submit', function(e) {
                var anyChecked = Array.from(checkboxes).some(function(cb) { return cb.checked; });
                if (!anyChecked) {
                    e.preventDefault();
                    alert('Please select at least one record category to back up.');
                    return;
                }

                var originalContent = downloadBtn.innerHTML;
                downloadBtn.disabled = true;
                downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing Backup...';

                // Re-enable after short delay to allow subsequent downloads
                setTimeout(function() {
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = originalContent;
                }, 4000);
            });
        }
    });
</script>

<?php include '../templates/footer.php'; ?>
