<?php
/**
 * Activity Logger Service
 * Comprehensive audit trail and timeline tracking for all system activities
 */

class ActivityLogger {
    private $conn;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }

    /**
     * Log an activity action
     * @param int $user_id User performing the action
     * @param string $entity_type Type of entity (users, requests, records, etc.)
     * @param int $entity_id ID of the entity
     * @param string $action Action performed (created, updated, approved, etc.)
     * @param string $action_category Category (request, approval, system, etc.)
     * @param array|null $old_values Previous values (for updates)
     * @param string|null $description Human-readable description
     * @return int Activity log ID or 0 on failure
     */
    public function logAction(
        $user_id,
        $entity_type,
        $entity_id,
        $action,
        $action_category,
        $old_values = null,
        $description = null
    ) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Get current values as new_values
        $new_values = $this->getEntityCurrentState($entity_type, $entity_id);

        $sql = "INSERT INTO activity_logs (user_id, entity_type, entity_id, action, action_category, old_values, new_values, description, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $old_json = $old_values ? json_encode($old_values) : null;
        $new_json = $new_values ? json_encode($new_values) : null;

        $stmt->bind_param('isiiissss', $user_id, $entity_type, $entity_id, $action, $action_category, $old_json, $new_json, $description, $ip_address, $user_agent);
        
        if ($stmt->execute()) {
            $activity_id = $stmt->insert_id;
            $stmt->close();
            return $activity_id;
        }

        $stmt->close();
        return 0;
    }

    /**
     * Log request status change with timeline entry
     * @param int $request_id Request ID
     * @param int $user_id User performing the action
     * @param string $action Action (approved, rejected, processing, etc.)
     * @param string $description Description
     * @return bool Success status
     */
    public function logRequestAction($request_id, $user_id, $action, $description = '') {
        // Log to activity_logs
        $activity_id = $this->logAction($user_id, 'requests', $request_id, $action, 'request', null, $description);

        if ($activity_id > 0) {
            // Also log to request_activity_timeline for quick access
            $sql = "INSERT INTO request_activity_timeline (request_id, activity_id, user_id, action) VALUES (?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('iiis', $request_id, $activity_id, $user_id, $action);
                $stmt->execute();
                $stmt->close();
            }
            return true;
        }

        return false;
    }

    /**
     * Get activity timeline for an entity
     * @param string $entity_type Type of entity
     * @param int $entity_id Entity ID
     * @param int $limit Limit results
     * @return array Activity timeline
     */
    public function getEntityTimeline($entity_type, $entity_id, $limit = 100) {
        $sql = "SELECT al.*, u.fullname as user_name, u.email as user_email
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.entity_type = ? AND al.entity_id = ?
                ORDER BY al.created_at DESC
                LIMIT ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('sii', $entity_type, $entity_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $timeline = [];

        while ($row = $result->fetch_assoc()) {
            $timeline[] = $row;
        }

        $stmt->close();
        return $timeline;
    }

    /**
     * Get request activity timeline
     * @param int $request_id Request ID
     * @return array Request timeline with all actions
     */
    public function getRequestTimeline($request_id) {
        $sql = "SELECT rat.*, u.fullname, u.email, al.description, al.old_values, al.new_values
                FROM request_activity_timeline rat
                JOIN activity_logs al ON rat.activity_id = al.activity_id
                LEFT JOIN users u ON rat.user_id = u.id
                WHERE rat.request_id = ?
                ORDER BY rat.timestamp_recorded ASC";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $timeline = [];

        while ($row = $result->fetch_assoc()) {
            $timeline[] = $row;
        }

        $stmt->close();
        return $timeline;
    }

    /**
     * Get user activity log
     * @param int $user_id User ID
     * @param int $days Number of recent days to fetch
     * @param int $limit Limit results
     * @return array User activities
     */
    public function getUserActivityLog($user_id, $days = 30, $limit = 100) {
        $sql = "SELECT al.*
                FROM activity_logs al
                WHERE al.user_id = ? AND al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY al.created_at DESC
                LIMIT ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('iii', $user_id, $days, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $activities = [];

        while ($row = $result->fetch_assoc()) {
            $activities[] = $row;
        }

        $stmt->close();
        return $activities;
    }

    /**
     * Get system activity log for audit purposes
     * @param string $action_category Category to filter by
     * @param int $days Number of days
     * @param int $limit Limit results
     * @return array System activities
     */
    public function getSystemActivityLog($action_category = null, $days = 30, $limit = 100) {
        $sql = "SELECT al.*, u.fullname as user_name
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $params = [$days];
        $types = 'i';

        if ($action_category) {
            $sql .= " AND al.action_category = ?";
            $params[] = $action_category;
            $types .= 's';
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $activities = [];

        while ($row = $result->fetch_assoc()) {
            $activities[] = $row;
        }

        $stmt->close();
        return $activities;
    }

    /**
     * Get entity current state (for comparison)
     * @param string $entity_type Type of entity
     * @param int $entity_id Entity ID
     * @return array|null Entity state
     */
    private function getEntityCurrentState($entity_type, $entity_id) {
        $table_map = [
            'requests' => 'requests',
            'users' => 'users',
            'announcements' => 'announcements',
            'records' => 'baptism_records',
        ];

        $table = $table_map[$entity_type] ?? $entity_type;
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

        $sql = "SELECT * FROM `$table` WHERE " . $this->getPrimaryKey($table) . " = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $entity_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Get primary key for table
     * @param string $table Table name
     * @return string Primary key column
     */
    private function getPrimaryKey($table) {
        $key_map = [
            'users' => 'id',
            'requests' => 'request_id',
            'announcements' => 'announcement_id',
        ];

        return $key_map[$table] ?? 'id';
    }

    /**
     * Generate audit report
     * @param string|null $entity_type Filter by entity type
     * @param int|null $user_id Filter by user
     * @param int $days Number of days to include
     * @return array Audit report
     */
    public function generateAuditReport($entity_type = null, $user_id = null, $days = 30) {
        $sql = "SELECT al.*, u.fullname as user_name, u.email
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $params = [$days];
        $types = 'i';

        if ($entity_type) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $entity_type;
            $types .= 's';
        }

        if ($user_id) {
            $sql .= " AND al.user_id = ?";
            $params[] = $user_id;
            $types .= 'i';
        }

        $sql .= " ORDER BY al.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $report = [];

        while ($row = $result->fetch_assoc()) {
            $report[] = $row;
        }

        $stmt->close();
        return $report;
    }
}
?>
