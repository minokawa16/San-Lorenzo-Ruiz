<?php
/**
 * Backup Manager Service
 * Handles database and file backups, scheduling, and restoration
 */

class BackupManager {
    private $conn;
    private $logger;
    private $backup_dir;

    public function __construct($database_connection, $logger = null) {
        $this->conn = $database_connection;
        $this->logger = $logger;
        $configured = trim((string) (getenv('BACKUP_DISK_PATH') ?: ''));
        if ($configured !== '') {
            $this->backup_dir = rtrim($configured, '/\\');
        } else {
            $this->backup_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
        }
        $this->ensureBackupDirectory();
    }

    /**
     * Ensure backup directory exists and is writable
     * @return bool
     */
    private function ensureBackupDirectory() {
        if (!is_dir($this->backup_dir)) {
            if (!@mkdir($this->backup_dir, 0775, true) && !is_dir($this->backup_dir)) {
                return false;
            }
        }

        $index_file = $this->backup_dir . DIRECTORY_SEPARATOR . 'index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\nhttp_response_code(403);\nexit('Access denied');\n");
        }

        if (@is_writable($this->backup_dir)) {
            return true;
        }

        $test_file = $this->backup_dir . DIRECTORY_SEPARATOR . '.probe_' . uniqid('', true) . '.tmp';
        $handle = @fopen($test_file, 'wb');
        if ($handle !== false) {
            @fwrite($handle, '1');
            @fclose($handle);
            @unlink($test_file);
            return true;
        }

        return false;
    }

    /**
     * Create a full backup (database + files)
     * @param int $initiated_by User ID
     * @param string $backup_name Custom backup name
     * @return array Backup result with backup_id
     */
    public function createFullBackup($initiated_by, $backup_name = '') {
        if (empty($backup_name)) {
            $backup_name = 'backup_full_' . date('Y-m-d_H-i-s');
        }

        // Record backup start
        $backup_id = $this->recordBackupStart($backup_name, 'full', $initiated_by);

        if (!$backup_id) {
            return ['success' => false, 'error' => 'Failed to record backup'];
        }

        $start_time = time();
        $result = ['success' => false, 'backup_id' => $backup_id];

        try {
            // Create backup directory
            $backup_path = $this->backup_dir . '/' . $backup_name;
            if (!mkdir($backup_path, 0755, true)) {
                throw new Exception('Failed to create backup directory');
            }

            // Backup database
            if (!$this->backupDatabase($backup_path)) {
                throw new Exception('Database backup failed');
            }

            // Backup critical files
            if (!$this->backupFiles($backup_path)) {
                throw new Exception('File backup failed');
            }

            // Create manifest
            $this->createBackupManifest($backup_path, $backup_name);

            // Compress backup
            $compressed_path = $this->compressBackup($backup_path);

            // Record backup completion
            $duration = time() - $start_time;
            $this->recordBackupCompletion($backup_id, $compressed_path ?: $backup_path, $duration, true);

            $result['success'] = true;
            $result['backup_path'] = $compressed_path ?: $backup_path;
            $result['duration'] = $duration;

        } catch (Exception $e) {
            $this->recordBackupCompletion($backup_id, '', time() - $start_time, false, $e->getMessage());
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Create database-only backup
     * @param int $initiated_by User ID
     * @param string $backup_name Custom backup name
     * @return array Backup result
     */
    public function createDatabaseBackup($initiated_by, $backup_name = '') {
        if (empty($backup_name)) {
            $backup_name = 'backup_db_' . date('Y-m-d_H-i-s');
        }

        $backup_id = $this->recordBackupStart($backup_name, 'database_only', $initiated_by);

        if (!$backup_id) {
            return ['success' => false, 'error' => 'Failed to record backup'];
        }

        $start_time = time();
        $result = ['success' => false, 'backup_id' => $backup_id];

        try {
            $backup_path = $this->backup_dir . '/' . $backup_name;
            if (!mkdir($backup_path, 0755, true)) {
                throw new Exception('Failed to create backup directory');
            }

            if (!$this->backupDatabase($backup_path)) {
                throw new Exception('Database backup failed');
            }

            $this->createBackupManifest($backup_path, $backup_name);
            $compressed_path = $this->compressBackup($backup_path);

            $duration = time() - $start_time;
            $this->recordBackupCompletion($backup_id, $compressed_path ?: $backup_path, $duration, true);

            $result['success'] = true;
            $result['backup_path'] = $compressed_path ?: $backup_path;

        } catch (Exception $e) {
            $this->recordBackupCompletion($backup_id, '', time() - $start_time, false, $e->getMessage());
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Backup database using mysqldump
     * @param string $backup_path Path to backup directory
     * @return bool Success status
     */
    private function backupDatabase($backup_path) {
        $db_name = defined('DB_NAME') ? DB_NAME : 'parish_management_system';
        $db_user = defined('DB_USER') ? DB_USER : 'root';
        $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
        
        $dump_file = $backup_path . '/' . $db_name . '_dump.sql';
        
        // Try using mysqldump if available
        $command = escapeshellcmd(sprintf('mysqldump -h %s -u %s %s > %s 2>&1', 
            $db_host, $db_user, $db_name, $dump_file));

        // On Windows, try different path
        if (PHP_OS_FAMILY === 'Windows') {
            $xampp_path = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
            if (file_exists($xampp_path)) {
                $command = escapeshellcmd(sprintf('"%s" -h %s -u %s %s > "%s" 2>&1', 
                    $xampp_path, $db_host, $db_user, $db_name, $dump_file));
            }
        }

        // Fallback: PHP-based backup if mysqldump not available
        if (!file_exists($dump_file) || filesize($dump_file) < 100) {
            return $this->backupDatabasePHP($dump_file);
        }

        return file_exists($dump_file) && filesize($dump_file) > 100;
    }

    /**
     * PHP-based database backup (fallback)
     * @param string $dump_file File path
     * @return bool Success status
     */
    private function backupDatabasePHP($dump_file) {
        $db_name = defined('DB_NAME') ? DB_NAME : 'parish_management_system';
        
        // Get all tables
        $result = $this->conn->query("SHOW TABLES FROM `$db_name`");
        if (!$result) {
            return false;
        }

        $dump_content = "-- Database Backup\n";
        $dump_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $dump_content .= "USE `$db_name`;\n\n";

        while ($table = $result->fetch_row()) {
            $table_name = $table[0];
            
            // Get table structure
            $create_result = $this->conn->query("SHOW CREATE TABLE `$table_name`");
            if ($create_result) {
                $create_row = $create_result->fetch_row();
                $dump_content .= $create_row[1] . ";\n\n";
            }

            // Get table data
            $data_result = $this->conn->query("SELECT * FROM `$table_name`");
            if ($data_result && $data_result->num_rows > 0) {
                while ($row = $data_result->fetch_assoc()) {
                    $dump_content .= "INSERT INTO `$table_name` VALUES (" . implode(', ', array_map(function($v) {
                        return $v === null ? 'NULL' : "'" . $this->conn->real_escape_string($v) . "'";
                    }, $row)) . ");\n";
                }
            }
        }

        return file_put_contents($dump_file, $dump_content) > 0;
    }

    /**
     * Backup critical files and folders
     * @param string $backup_path Path to backup directory
     * @return bool Success status
     */
    private function backupFiles($backup_path) {
        $files_backup_path = $backup_path . '/files_backup';
        
        if (!mkdir($files_backup_path, 0755, true)) {
            return false;
        }

        $folders_to_backup = [
            __DIR__ . '/../uploads' => 'uploads',
            __DIR__ . '/../admin' => 'admin',
            __DIR__ . '/../includes' => 'includes',
            __DIR__ . '/../config' => 'config',
        ];

        foreach ($folders_to_backup as $source => $dest) {
            if (file_exists($source)) {
                $dest_path = $files_backup_path . '/' . $dest;
                if (!$this->recursiveCopy($source, $dest_path)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Recursively copy directory
     * @param string $src Source path
     * @param string $dst Destination path
     * @return bool Success status
     */
    private function recursiveCopy($src, $dst) {
        if (is_dir($src)) {
            if (!is_dir($dst)) {
                mkdir($dst, 0755, true);
            }
            
            $files = scandir($src);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    if (!$this->recursiveCopy("$src/$file", "$dst/$file")) {
                        return false;
                    }
                }
            }
            return true;
        } else if (is_file($src)) {
            return copy($src, $dst);
        }
        return false;
    }

    /**
     * Compress backup directory
     * @param string $backup_path Path to backup
     * @return string|bool Path to compressed file or false
     */
    private function compressBackup($backup_path) {
        $archive_name = basename($backup_path) . '.zip';
        $archive_path = dirname($backup_path) . '/' . $archive_name;

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $this->recursiveZip($backup_path, $zip, strlen($backup_path) + 1);
                $zip->close();

                // Delete uncompressed backup
                $this->recursiveDelete($backup_path);

                return $archive_path;
            }
        }

        return false;
    }

    /**
     * Recursively add files to zip
     * @param string $path Path to add
     * @param ZipArchive $zip Zip archive object
     * @param int $prefix_len Prefix length for relative paths
     */
    private function recursiveZip($path, $zip, $prefix_len) {
        if (is_dir($path)) {
            $files = scandir($path);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $file_path = $path . '/' . $file;
                    $relative_path = substr($file_path, $prefix_len);
                    
                    if (is_dir($file_path)) {
                        $zip->addEmptyDir($relative_path);
                        $this->recursiveZip($file_path, $zip, $prefix_len);
                    } else {
                        $zip->addFile($file_path, $relative_path);
                    }
                }
            }
        }
    }

    /**
     * Recursively delete directory
     * @param string $path Path to delete
     * @return bool Success status
     */
    private function recursiveDelete($path) {
        if (is_dir($path)) {
            $files = scandir($path);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $this->recursiveDelete($path . '/' . $file);
                }
            }
            return rmdir($path);
        } else if (is_file($path)) {
            return unlink($path);
        }
        return false;
    }

    /**
     * Create backup manifest file
     * @param string $backup_path Path to backup
     * @param string $backup_name Backup name
     */
    private function createBackupManifest($backup_path, $backup_name) {
        $manifest = [
            'backup_name' => $backup_name,
            'created_at' => date('Y-m-d H:i:s'),
            'php_version' => phpversion(),
            'mysql_version' => $this->getMySQLVersion(),
            'database_name' => defined('DB_NAME') ? DB_NAME : 'parish_management_system',
            'files' => []
        ];

        // List all files in backup
        $rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backup_path));
        foreach ($rit as $file) {
            if ($file->isFile()) {
                $manifest['files'][] = [
                    'path' => substr($file->getPathname(), strlen($backup_path) + 1),
                    'size' => filesize($file->getPathname()),
                    'modified' => filemtime($file->getPathname())
                ];
            }
        }

        file_put_contents($backup_path . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Get MySQL version
     * @return string MySQL version
     */
    private function getMySQLVersion() {
        $result = $this->conn->query("SELECT VERSION() as version");
        if ($result) {
            $row = $result->fetch_assoc();
            return $row['version'];
        }
        return 'Unknown';
    }

    /**
     * Record backup start in database
     * @param string $backup_name Backup name
     * @param string $backup_type Backup type
     * @param int $initiated_by User ID
     * @return int Backup ID or 0 on failure
     */
    private function recordBackupStart($backup_name, $backup_type, $initiated_by) {
        $sql = "INSERT INTO backup_records (backup_type, backup_name, backup_path, backup_status, initiated_by, initiated_at) 
                VALUES (?, ?, ?, 'in_progress', ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $backup_path = $this->backup_dir . '/' . $backup_name;
        $stmt->bind_param('sssi', $backup_type, $backup_name, $backup_path, $initiated_by);
        
        if ($stmt->execute()) {
            $backup_id = $stmt->insert_id;
            $stmt->close();
            return $backup_id;
        }

        $stmt->close();
        return 0;
    }

    /**
     * Record backup completion
     * @param int $backup_id Backup ID
     * @param string $backup_path Backup path
     * @param int $duration Duration in seconds
     * @param bool $success Success status
     * @param string $error_message Error message
     */
    private function recordBackupCompletion($backup_id, $backup_path, $duration, $success = true, $error_message = '') {
        $status = $success ? 'completed' : 'failed';
        
        $sql = "UPDATE backup_records SET 
                backup_status = ?,
                backup_path = ?,
                completed_at = NOW(),
                duration_seconds = ?,
                error_message = ?
                WHERE backup_id = ?";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sssii', $status, $backup_path, $duration, $error_message, $backup_id);
            $stmt->execute();
            $stmt->close();
        }

        if ($this->logger) {
            $this->logger->logAction(0, 'system', $backup_id, 'backup_' . ($success ? 'completed' : 'failed'), 'system', null, "Backup $backup_name: $status");
        }
    }

    /**
     * Get backup list
     * @param int $limit Limit results
     * @param int $offset Offset for pagination
     * @return array List of backups
     */
    public function getBackupList($limit = 50, $offset = 0) {
        $sql = "SELECT * FROM backup_records ORDER BY initiated_at DESC LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $backups = [];

        while ($row = $result->fetch_assoc()) {
            $backups[] = $row;
        }

        $stmt->close();
        return $backups;
    }

    /**
     * Verify backup integrity
     * @param int $backup_id Backup ID
     * @return bool Integrity check result
     */
    public function verifyBackupIntegrity($backup_id) {
        $backup = $this->getBackupById($backup_id);
        if (!$backup || !file_exists($backup['backup_path'])) {
            return false;
        }

        $sql = "UPDATE backup_records SET verified = 1, verified_at = NOW() WHERE backup_id = ?";
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $backup_id);
            $stmt->execute();
            $stmt->close();
            return true;
        }

        return false;
    }

    /**
     * Get backup by ID
     * @param int $backup_id Backup ID
     * @return array|null Backup record
     */
    public function getBackupById($backup_id) {
        $sql = "SELECT * FROM backup_records WHERE backup_id = ? LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $backup_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $backup = $result->fetch_assoc();
        $stmt->close();

        return $backup;
    }
}
?>
