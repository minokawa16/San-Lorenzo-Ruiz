<?php
require_once __DIR__ . '/../includes/audit.php';

final class AuditLogService
{
    public function __construct(private mysqli $db) {}

    /**
     * Retrieves summary counts for top metric cards.
     */
    public function getSummaryMetrics(): array
    {
        // 1. Total events today
        $totalToday = 0;
        $q1 = $this->db->query("SELECT COUNT(*) c FROM audit_log WHERE created_at >= CURDATE()");
        if ($q1) {
            $totalToday = (int)($q1->fetch_assoc()['c'] ?? 0);
            $q1->close();
        }

        // 2. Administrative actions in the past 7 days
        $admin7d = 0;
        $q2 = $this->db->query("SELECT COUNT(*) c FROM audit_log l LEFT JOIN users u ON u.id = l.user_id 
            WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
            AND (l.user_role IN ('admin','administrator','staff','secretary','priest') 
                 OR u.role IN ('admin','administrator','staff','secretary','priest')
                 OR l.action LIKE '%APPROVE%' OR l.action LIKE '%REJECT%' OR l.action LIKE '%ARCHIVE%')");
        if ($q2) {
            $admin7d = (int)($q2->fetch_assoc()['c'] ?? 0);
            $q2->close();
        }

        // 3. Failed login & auth attempts (warning level)
        $failedLogins = 0;
        $q3 = $this->db->query("SELECT COUNT(*) c FROM audit_log 
            WHERE action LIKE '%LOGIN%FAIL%' OR action LIKE '%OTP%FAIL%' OR action LIKE '%FAILURE%' 
               OR (event_category = 'AUTH' AND severity = 'WARNING')");
        if ($q3) {
            $failedLogins = (int)($q3->fetch_assoc()['c'] ?? 0);
            $q3->close();
        }

        // 4. Critical data events (records archived, deletions, role shifts)
        $criticalEvents = 0;
        $q4 = $this->db->query("SELECT COUNT(*) c FROM audit_log 
            WHERE severity = 'CRITICAL' OR action LIKE '%ARCHIVE%' OR action LIKE '%DELETE%' OR action LIKE '%PURGE%'");
        if ($q4) {
            $criticalEvents = (int)($q4->fetch_assoc()['c'] ?? 0);
            $q4->close();
        }

        return [
            'total_today' => $totalToday,
            'admin_actions_7d' => $admin7d,
            'failed_logins' => $failedLogins,
            'critical_events' => $criticalEvents,
        ];
    }

    public function page(array $filters, int $page = 1, int $perPage = 50, int $hardLimit = 10000): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        [$where, $types, $values] = $this->where($filters);

        $count = $this->db->prepare("SELECT COUNT(*) c FROM audit_log l LEFT JOIN users u ON u.id = l.user_id $where");
        if ($types !== '') {
            $count->bind_param($types, ...$values);
        }
        $count->execute();
        $total = (int)($count->get_result()->fetch_assoc()['c'] ?? 0);
        $count->close();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT 
                    l.log_id,
                    l.created_at,
                    l.user_id,
                    COALESCE(l.user_name, u.fullname, 'System') AS actor,
                    COALESCE(l.user_role, u.role, 'system') AS actor_role,
                    l.action,
                    COALESCE(l.severity, 'INFO') AS severity,
                    COALESCE(l.event_category, 'SYSTEM') AS event_category,
                    COALESCE(l.target_type, l.table_name, 'system') AS target_type,
                    COALESCE(l.target_id, l.record_id) AS target_id,
                    COALESCE(l.description, 'Activity logged.') AS description,
                    COALESCE(l.old_values, l.old_value) AS old_values,
                    COALESCE(l.new_values, l.new_value) AS new_values,
                    l.table_name,
                    l.record_id,
                    l.old_value,
                    l.new_value,
                    l.ip_address,
                    l.user_agent,
                    l.correlation_id,
                    l.component,
                    l.event
                FROM audit_log l 
                LEFT JOIN users u ON u.id = l.user_id 
                $where 
                ORDER BY l.created_at DESC, l.log_id DESC 
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $bindTypes = $types . 'ii';
        $bind = [...$values, $perPage, $offset];
        $stmt->bind_param($bindTypes, ...$bind);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $row['old_value'] = tugonRedactSensitive((string)$row['old_value']);
            $row['new_value'] = tugonRedactSensitive((string)$row['new_value']);
            $row['old_values'] = tugonRedactSensitive((string)$row['old_values']);
            $row['new_values'] = tugonRedactSensitive((string)$row['new_values']);
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'truncated' => $total > $hardLimit,
            'limit' => $hardLimit
        ];
    }

    public function exportRows(array $filters, int $limit = 10000): array
    {
        [$where, $types, $values] = $this->where($filters);
        $limit = max(1, min(10000, $limit));

        $sql = "SELECT 
                    l.created_at,
                    COALESCE(l.user_name, u.fullname, 'System') AS actor,
                    COALESCE(l.user_role, u.role, 'system') AS actor_role,
                    COALESCE(l.event_category, 'SYSTEM') AS event_category,
                    l.action,
                    COALESCE(l.severity, 'INFO') AS severity,
                    COALESCE(l.target_type, l.table_name, 'system') AS target_type,
                    COALESCE(l.target_id, l.record_id) AS target_id,
                    COALESCE(l.description, 'Activity logged.') AS description,
                    l.ip_address,
                    l.user_agent,
                    l.correlation_id,
                    l.component,
                    l.event,
                    l.table_name,
                    l.record_id
                FROM audit_log l 
                LEFT JOIN users u ON u.id = l.user_id 
                $where 
                ORDER BY l.created_at DESC, l.log_id DESC 
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $bindTypes = $types . 'i';
        $bind = [...$values, $limit];
        $stmt->bind_param($bindTypes, ...$bind);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function where(array $f): array
    {
        $clauses = [];
        $types = '';
        $values = [];

        if (($f['q'] ?? '') !== '') {
            $like = '%' . mb_strimwidth((string)$f['q'], 0, 100, '') . '%';
            $clauses[] = '(l.action LIKE ? OR l.description LIKE ? OR l.target_type LIKE ? OR l.table_name LIKE ? OR l.correlation_id LIKE ? OR l.user_name LIKE ? OR u.fullname LIKE ? OR l.ip_address LIKE ?)';
            $types .= 'ssssssss';
            array_push($values, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if (($f['from'] ?? '') !== '') {
            $clauses[] = 'l.created_at >= ?';
            $types .= 's';
            $values[] = $f['from'] . ' 00:00:00';
        }

        if (($f['to'] ?? '') !== '') {
            $clauses[] = 'l.created_at <= ?';
            $types .= 's';
            $values[] = $f['to'] . ' 23:59:59';
        }

        if (!empty($f['category'])) {
            $clauses[] = 'l.event_category = ?';
            $types .= 's';
            $values[] = (string)$f['category'];
        }

        if (!empty($f['severity'])) {
            $clauses[] = 'l.severity = ?';
            $types .= 's';
            $values[] = (string)$f['severity'];
        }

        if (!empty($f['actor'])) {
            $actorLike = '%' . mb_strimwidth((string)$f['actor'], 0, 80, '') . '%';
            $clauses[] = '(l.user_name LIKE ? OR u.fullname LIKE ? OR l.user_role LIKE ?)';
            $types .= 'sss';
            array_push($values, $actorLike, $actorLike, $actorLike);
        }

        if (($f['component'] ?? '') !== '') {
            $clauses[] = 'l.component = ?';
            $types .= 's';
            $values[] = $f['component'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $types, $values];
    }
}
