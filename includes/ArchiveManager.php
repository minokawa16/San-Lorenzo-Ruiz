<?php
/**
 * Archive Manager Service
 * Handles soft deletes, archive operations, and restoration of archived records
 */

class ArchiveManager {
    private $conn;
    private $logger;

    public function __construct($database_connection, $logger = null) {
        $this->conn = $database_connection;
        $this->logger = $logger;
    }

    /**
     * Archive a record (soft delete)
     * @param string $table Table name
     * @param int $record_id Primary key value
     * @param int $user_id User performing the action
     * @param string $reason Archive reason
     * @return bool Success status
     */
    public function archiveRecord($table, $record_id, $user_id, $reason = '') {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        $sql = "UPDATE `$table` SET deleted_at = NOW(), archived_by = ?, archive_reason = ? WHERE " . $this->getPrimaryKey($table) . " = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('isi', $user_id, $reason, $record_id);
        $result = $stmt->execute();
        $stmt->close();

        if ($result && $this->logger) {
            $this->logger->logAction($user_id, $table, $record_id, 'archived', 'system', null, "Record archived. Reason: $reason");
        }

        return $result;
    }

    /**
     * Restore an archived record
     * @param string $table Table name
     * @param int $record_id Primary key value
     * @param int $user_id User performing the action
     * @return bool Success status
     */
    public function restoreRecord($table, $record_id, $user_id) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        $sql = "UPDATE `$table` SET deleted_at = NULL, archived_by = NULL, archive_reason = NULL WHERE " . $this->getPrimaryKey($table) . " = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $record_id);
        $result = $stmt->execute();
        $stmt->close();

        if ($result && $this->logger) {
            $this->logger->logAction($user_id, $table, $record_id, 'restored', 'system', null, 'Record restored from archive');
        }

        return $result;
    }

    /**
     * Get archived records for a table
     * @param string $table Table name
     * @param int $limit Limit results
     * @param int $offset Offset for pagination
     * @return array Array of archived records
     */
    public function getArchivedRecords($table, $limit = 50, $offset = 0) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        $sql = "SELECT * FROM `$table` WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT ? OFFSET ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        
        $stmt->close();
        return $records;
    }

    /**
     * Get active (non-archived) records
     * @param string $table Table name
     * @param int $limit Limit results
     * @param int $offset Offset for pagination
     * @return array Array of active records
     */
    public function getActiveRecords($table, $limit = 50, $offset = 0) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        $sql = "SELECT * FROM `$table` WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        
        $stmt->close();
        return $records;
    }

    /**
     * Count archived records
     * @param string $table Table name
     * @return int Count of archived records
     */
    public function countArchivedRecords($table) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        $sql = "SELECT COUNT(*) as count FROM `$table` WHERE deleted_at IS NOT NULL";
        
        $result = $this->conn->query($sql);
        if ($result) {
            return $result->fetch_assoc()['count'];
        }
        return 0;
    }

    /**
     * Permanently delete archived records older than specified days
     * @param string $table Table name
     * @param int $days Number of days
     * @param int $user_id User performing the action
     * @return int Number of records deleted
     */
    public function purgeOldArchives($table, $days = 90, $user_id = 0) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        $sql = "SELECT " . $this->getPrimaryKey($table) . " as id FROM `$table` WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = 0;
        
        while ($row = $result->fetch_assoc()) {
            $delete_sql = "DELETE FROM `$table` WHERE " . $this->getPrimaryKey($table) . " = ?";
            $delete_stmt = $this->conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param('i', $row['id']);
                if ($delete_stmt->execute()) {
                    $count++;
                }
                $delete_stmt->close();
            }
        }
        
        $stmt->close();

        if ($count > 0 && $this->logger) {
            $this->logger->logAction($user_id, 'system', 0, 'archive_purge', 'system', null, "Purged $count old archived records from $table");
        }

        return $count;
    }

    /**
     * Get primary key column name for a table
     * @param string $table Table name
     * @return string Primary key column name
     */
    private function getPrimaryKey($table) {
        $key_map = [
            'users' => 'id',
            'requests' => 'request_id',
            'announcements' => 'announcement_id',
            'baptism_records' => 'baptism_id',
            'confirmation_records' => 'confirmation_id',
            'first_communion_records' => 'communion_id',
            'marriage_records' => 'marriage_id',
            'funeral_records' => 'funeral_id',
        ];

        return $key_map[$table] ?? 'id';
    }

    /**
     * Check if table supports archiving
     * @param string $table Table name
     * @return bool
     */
    public function supportsArchiving($table) {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        return in_array($table, [
            'requests', 'announcements', 'baptism_records', 'confirmation_records',
            'first_communion_records', 'marriage_records', 'funeral_records'
        ], true);
    }
}
?>
