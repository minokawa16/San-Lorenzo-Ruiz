<?php
/**
 * Calendar Conflict Detection Service
 * Manages reservation scheduling and detects conflicts between events
 */

class ConflictDetectionService {
    private $conn;
    private $logger;

    public function __construct($database_connection, $logger = null) {
        $this->conn = $database_connection;
        $this->logger = $logger;
    }

    /**
     * Check for conflicts when creating a new event
     * @param int $event_id Event ID (0 for new events)
     * @param string $event_type Type of event
     * @param DateTime $start_time Event start time
     * @param DateTime $end_time Event end time
     * @param array $resources Resources needed (venue, priest, etc.)
     * @return array Conflicts found and severity
     */
    public function checkConflicts($event_id, $event_type, $start_time, $end_time, $resources = []) {
        $conflicts = [];
        $severity = 'low';

        // Check time-based conflicts
        $time_conflicts = $this->checkTimeConflicts($event_type, $start_time, $end_time, $event_id);
        if (!empty($time_conflicts)) {
            $conflicts = array_merge($conflicts, $time_conflicts);
            $severity = 'high';
        }

        // Check resource conflicts
        $resource_conflicts = $this->checkResourceConflicts($event_id, $start_time, $end_time, $resources);
        if (!empty($resource_conflicts)) {
            $conflicts = array_merge($conflicts, $resource_conflicts);
            if ($severity !== 'high') {
                $severity = 'medium';
            }
        }

        // Check availability constraints
        $availability_conflicts = $this->checkAvailabilityConstraints($start_time, $end_time, $resources);
        if (!empty($availability_conflicts)) {
            $conflicts = array_merge($conflicts, $availability_conflicts);
        }

        return [
            'has_conflicts' => !empty($conflicts),
            'conflicts' => $conflicts,
            'severity' => $severity,
            'can_proceed' => empty($conflicts)
        ];
    }

    /**
     * Check for time-based conflicts
     * @param string $event_type Type of event
     * @param DateTime $start_time Event start
     * @param DateTime $end_time Event end
     * @param int $exclude_event_id Event to exclude from check
     * @return array Conflicting events
     */
    private function checkTimeConflicts($event_type, $start_time, $end_time, $exclude_event_id = 0) {
        $conflicts = [];
        
        // Get overlapping events
        $sql = "SELECT * FROM schedule_events 
                WHERE (
                    (start_time < ? AND end_time > ?) OR
                    (start_time >= ? AND start_time < ?) OR
                    (end_time > ? AND end_time <= ?)
                )
                AND status != 'cancelled'";

        $params = [
            $end_time->format('Y-m-d H:i:s'),
            $start_time->format('Y-m-d H:i:s'),
            $start_time->format('Y-m-d H:i:s'),
            $end_time->format('Y-m-d H:i:s'),
            $start_time->format('Y-m-d H:i:s'),
            $end_time->format('Y-m-d H:i:s')
        ];

        if ($exclude_event_id > 0) {
            $sql .= " AND event_id != ?";
            $params[] = $exclude_event_id;
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $types = str_repeat('s', count($params) - 1) . (strpos('i', 'i') !== false ? 'i' : '');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $conflicts[] = [
                'type' => 'time_conflict',
                'conflicting_event_id' => $row['event_id'],
                'event_name' => $row['event_name'],
                'conflict_time' => $row['start_time'] . ' to ' . $row['end_time'],
                'severity' => 'high'
            ];
        }

        $stmt->close();
        return $conflicts;
    }

    /**
     * Check for resource conflicts
     * @param int $event_id Event ID
     * @param DateTime $start_time Event start
     * @param DateTime $end_time Event end
     * @param array $resources Resources to check
     * @return array Resource conflicts
     */
    private function checkResourceConflicts($event_id, $start_time, $end_time, $resources) {
        $conflicts = [];

        foreach ($resources as $resource) {
            // Check if resource is already reserved
            $sql = "SELECT rr.*, se.event_name FROM resource_reservations rr
                    JOIN schedule_events se ON rr.event_id = se.event_id
                    WHERE rr.resource_id = ?
                    AND rr.status = 'confirmed'
                    AND (
                        (se.start_time < ? AND se.end_time > ?) OR
                        (se.start_time >= ? AND se.start_time < ?)
                    )";

            if ($event_id > 0) {
                $sql .= " AND rr.event_id != ?";
            }

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                continue;
            }

            if ($event_id > 0) {
                $stmt->bind_param('sssssi', $resource['id'], $end_time->format('Y-m-d H:i:s'), 
                                 $start_time->format('Y-m-d H:i:s'), $start_time->format('Y-m-d H:i:s'),
                                 $end_time->format('Y-m-d H:i:s'), $event_id);
            } else {
                $stmt->bind_param('isssss', $resource['id'], $end_time->format('Y-m-d H:i:s'),
                                 $start_time->format('Y-m-d H:i:s'), $start_time->format('Y-m-d H:i:s'),
                                 $end_time->format('Y-m-d H:i:s'));
            }

            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $conflicts[] = [
                    'type' => 'resource_conflict',
                    'resource_type' => $resource['type'] ?? 'unknown',
                    'resource_name' => $resource['name'] ?? 'Unknown Resource',
                    'conflicting_event' => $row['event_name'],
                    'severity' => 'medium'
                ];
            }

            $stmt->close();
        }

        return $conflicts;
    }

    /**
     * Check availability constraints
     * @param DateTime $start_time Event start
     * @param DateTime $end_time Event end
     * @param array $resources Resources to check
     * @return array Availability issues
     */
    private function checkAvailabilityConstraints($start_time, $end_time, $resources) {
        $conflicts = [];

        foreach ($resources as $resource) {
            // Get resource constraints
            $sql = "SELECT * FROM reservation_resources WHERE resource_id = ? AND is_available = 0";

            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $resource['id']);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $conflicts[] = [
                        'type' => 'availability_constraint',
                        'resource_name' => $resource['name'] ?? 'Unknown',
                        'message' => 'Resource is currently unavailable',
                        'severity' => 'high'
                    ];
                }

                $stmt->close();
            }

            // Check time window constraints
            if (isset($resource['available_from']) && isset($resource['available_to'])) {
                $event_start_time = $start_time->format('H:i:s');
                $event_end_time = $end_time->format('H:i:s');

                if ($event_start_time < $resource['available_from'] || $event_end_time > $resource['available_to']) {
                    $conflicts[] = [
                        'type' => 'time_window_conflict',
                        'resource_name' => $resource['name'] ?? 'Unknown',
                        'available_from' => $resource['available_from'],
                        'available_to' => $resource['available_to'],
                        'message' => 'Resource is not available during requested time',
                        'severity' => 'medium'
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Register a conflict in the database
     * @param int $event_id Event ID
     * @param int $conflicting_event_id Conflicting event ID
     * @param string $conflict_type Type of conflict
     * @param string $description Description
     * @return int Conflict ID or 0 on failure
     */
    public function registerConflict($event_id, $conflicting_event_id, $conflict_type, $description) {
        $sql = "INSERT INTO calendar_event_conflicts (event_id, conflicting_event_id, conflict_type, description) 
                VALUES (?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('iiss', $event_id, $conflicting_event_id, $conflict_type, $description);

        if ($stmt->execute()) {
            $conflict_id = $stmt->insert_id;
            $stmt->close();

            if ($this->logger) {
                $this->logger->logAction(0, 'calendar_conflicts', $conflict_id, 'detected', 'system', null, $description);
            }

            return $conflict_id;
        }

        $stmt->close();
        return 0;
    }

    /**
     * Get unresolved conflicts
     * @param int $limit Limit results
     * @return array List of conflicts
     */
    public function getUnresolvedConflicts($limit = 100) {
        $sql = "SELECT cec.*, se1.event_name as event_name, se2.event_name as conflicting_event_name
                FROM calendar_event_conflicts cec
                JOIN schedule_events se1 ON cec.event_id = se1.event_id
                JOIN schedule_events se2 ON cec.conflicting_event_id = se2.event_id
                WHERE cec.resolved = 0
                ORDER BY cec.severity DESC, cec.detected_at DESC
                LIMIT ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $conflicts = [];

        while ($row = $result->fetch_assoc()) {
            $conflicts[] = $row;
        }

        $stmt->close();
        return $conflicts;
    }

    /**
     * Resolve a conflict
     * @param int $conflict_id Conflict ID
     * @param int $user_id User resolving the conflict
     * @param string $resolution_action Action taken to resolve
     * @return bool Success status
     */
    public function resolveConflict($conflict_id, $user_id, $resolution_action) {
        $sql = "UPDATE calendar_event_conflicts 
                SET resolved = 1, resolution_action = ?, resolved_by = ?, resolved_at = NOW()
                WHERE conflict_id = ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sii', $resolution_action, $user_id, $conflict_id);
        $result = $stmt->execute();
        $stmt->close();

        if ($result && $this->logger) {
            $this->logger->logAction($user_id, 'calendar_conflicts', $conflict_id, 'resolved', 'system', null, "Resolution: $resolution_action");
        }

        return $result;
    }

    /**
     * Get available time slots
     * @param DateTime $start_date Date to check
     * @param DateTime $end_date End date
     * @param int $duration_minutes Event duration in minutes
     * @param array $resources Resources needed
     * @return array Available time slots
     */
    public function getAvailableTimeSlots($start_date, $end_date, $duration_minutes = 60, $resources = []) {
        $available_slots = [];
        $current = $start_date;

        while ($current <= $end_date) {
            for ($hour = 8; $hour < 18; $hour += 1) {
                $slot_start = clone $current;
                $slot_start->setTime($hour, 0);
                $slot_end = clone $slot_start;
                $slot_end->modify("+{$duration_minutes} minutes");

                // Check if slot is available
                $conflicts = $this->checkConflicts(0, 'general', $slot_start, $slot_end, $resources);

                if (!$conflicts['has_conflicts']) {
                    $available_slots[] = [
                        'start' => $slot_start->format('Y-m-d H:i:s'),
                        'end' => $slot_end->format('Y-m-d H:i:s'),
                        'formatted' => $slot_start->format('M d, Y at h:i A')
                    ];
                }
            }

            $current->modify('+1 day');
        }

        return $available_slots;
    }

    /**
     * Reserve a resource for an event
     * @param int $event_id Event ID
     * @param int $resource_id Resource ID
     * @param int $reserved_by User ID
     * @return bool Success status
     */
    public function reserveResource($event_id, $resource_id, $reserved_by) {
        $sql = "INSERT INTO resource_reservations (event_id, resource_id, reserved_by, status) 
                VALUES (?, ?, ?, 'confirmed')";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('iii', $event_id, $resource_id, $reserved_by);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}
?>
