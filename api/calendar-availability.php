<?php
/**
 * Calendar Availability Engine API
 * Real-time availability calculation, occupied slot detection, and double-booking prevention.
 */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/ResourceAvailabilityService.php';

requireLogin();

$dateStr = trim((string) ($_GET['date'] ?? ''));
if ($dateStr === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
    $dateStr = date('Y-m-d', strtotime('+1 day'));
}

$rawResourceIds = $_GET['resource_ids'] ?? [];
if (is_string($rawResourceIds)) {
    $rawResourceIds = explode(',', $rawResourceIds);
}
$resourceIds = array_values(array_unique(array_filter(array_map('intval', (array) $rawResourceIds), fn($id) => $id > 0)));

// If no resources selected, default to all available active resources
if (empty($resourceIds)) {
    $rRes = $conn->query("SELECT resource_id FROM resources WHERE status='available' AND deleted_at IS NULL");
    if ($rRes) {
        while ($row = $rRes->fetch_assoc()) {
            $resourceIds[] = (int) $row['resource_id'];
        }
    }
}

$duration = max(15, min(480, intval($_GET['duration'] ?? 60)));
$bufferMinutes = max(0, min(120, intval($_GET['buffer'] ?? 30)));
$setupBuffer = intval(ceil($bufferMinutes / 2));
$cleanupBuffer = intval(floor($bufferMinutes / 2));

// 1. Fetch active reservations on that date overlapping with selected resources
$occupiedWindows = [];
$occupiedIntervals = [];

if (!empty($resourceIds)) {
    $placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
    $types = 's' . str_repeat('i', count($resourceIds));
    $params = array_merge([$dateStr], $resourceIds);

    $sql = "SELECT r.reservation_id, r.reservation_type, r.start_at, r.end_at,
                   r.service_duration_minutes, r.setup_duration_minutes, r.cleanup_duration_minutes,
                   r.status, GROUP_CONCAT(DISTINCT x.name SEPARATOR ', ') AS resource_names
            FROM reservations r
            JOIN reservation_resources rr ON rr.reservation_id = r.reservation_id
            JOIN resources x ON x.resource_id = rr.resource_id
            WHERE DATE(r.start_at) = ?
              AND rr.resource_id IN ($placeholders)
              AND r.status NOT IN ('cancelled', 'rejected')
            GROUP BY r.reservation_id
            ORDER BY r.start_at ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $startTs = strtotime($row['start_at']);
            $endTs = strtotime($row['end_at']);
            $setupMin = max((int) $row['setup_duration_minutes'], $setupBuffer);
            $cleanupMin = max((int) $row['cleanup_duration_minutes'], $cleanupBuffer);

            $bufStartTs = $startTs - ($setupMin * 60);
            $bufEndTs = $endTs + ($cleanupMin * 60);

            $occupiedWindows[] = [
                'window_start' => $bufStartTs,
                'window_end' => $bufEndTs,
                'service_start' => $startTs,
                'service_end' => $endTs,
                'type' => $row['reservation_type'],
                'status' => $row['status'],
                'resources' => $row['resource_names']
            ];

            $occupiedIntervals[] = [
                'reservation_id' => (int) $row['reservation_id'],
                'type' => ucwords(str_replace('_', ' ', (string) $row['reservation_type'])),
                'status' => $row['status'],
                'start_time' => date('H:i', $startTs),
                'end_time' => date('H:i', $endTs),
                'start_display' => date('g:i A', $startTs),
                'end_display' => date('g:i A', $endTs),
                'buffer_start' => date('H:i', $bufStartTs),
                'buffer_end' => date('H:i', $bufEndTs),
                'resources' => $row['resource_names']
            ];
        }
        $stmt->close();
    }

    // Check resource unavailabilities / blackouts
    $bTypes = 's' . str_repeat('i', count($resourceIds));
    $bParams = array_merge([$dateStr], $resourceIds);
    $bSql = "SELECT u.*, x.name AS resource_name 
             FROM resource_unavailability u
             JOIN resources x ON x.resource_id = u.resource_id
             WHERE u.resource_id IN ($placeholders)
               AND (u.recurrence_rule IS NOT NULL OR DATE(u.start_at) <= ? AND DATE(u.end_at) >= ?)";
    $bTypes = str_repeat('i', count($resourceIds)) . 'ss';
    $bParams = array_merge($resourceIds, [$dateStr, $dateStr]);
    $bStmt = $conn->prepare($bSql);
    if ($bStmt) {
        $bStmt->bind_param($bTypes, ...$bParams);
        $bStmt->execute();
        $bRes = $bStmt->get_result();
        $availService = new ResourceAvailabilityService($conn);
        while ($bRow = $bRes->fetch_assoc()) {
            $dayStart = strtotime($dateStr . ' 00:00:00');
            $dayEnd = strtotime($dateStr . ' 23:59:59');
            $bStartTs = strtotime($bRow['start_at']);
            $bEndTs = strtotime($bRow['end_at']);

            $occupiedWindows[] = [
                'window_start' => max($dayStart, $bStartTs),
                'window_end' => min($dayEnd, $bEndTs),
                'service_start' => max($dayStart, $bStartTs),
                'service_end' => min($dayEnd, $bEndTs),
                'type' => 'blackout',
                'status' => 'unavailable',
                'resources' => $bRow['resource_name'],
                'reason' => $bRow['reason']
            ];

            $occupiedIntervals[] = [
                'reservation_id' => 0,
                'type' => 'Parish Maintenance / Blackout',
                'status' => 'unavailable',
                'start_time' => date('H:i', max($dayStart, $bStartTs)),
                'end_time' => date('H:i', min($dayEnd, $bEndTs)),
                'start_display' => date('g:i A', max($dayStart, $bStartTs)),
                'end_display' => date('g:i A', min($dayEnd, $bEndTs)),
                'buffer_start' => date('H:i', max($dayStart, $bStartTs)),
                'buffer_end' => date('H:i', min($dayEnd, $bEndTs)),
                'resources' => $bRow['resource_name']
            ];
        }
        $bStmt->close();
    }
}

// 2. Generate standard parish service time slots (e.g. 8:00 AM to 5:00 PM)
$baseSlots = [
    '08:00', '09:00', '10:00', '11:00',
    '13:00', '14:00', '15:00', '16:00', '17:00'
];

$now = time();
$generatedSlots = [];

foreach ($baseSlots as $timeStr) {
    $slotStartTs = strtotime($dateStr . ' ' . $timeStr . ':00');
    $slotEndTs = $slotStartTs + ($duration * 60);

    // With caller's requested setup and cleanup buffer
    $slotBufStartTs = $slotStartTs - ($setupBuffer * 60);
    $slotBufEndTs = $slotEndTs + ($cleanupBuffer * 60);

    $isPast = ($slotStartTs <= $now);
    $conflictReason = null;
    $isOccupied = false;
    $isBufferConflict = false;

    if ($isPast) {
        $conflictReason = 'Past Time';
    } else {
        foreach ($occupiedWindows as $window) {
            // Check direct service overlap
            if ($slotStartTs < $window['service_end'] && $slotEndTs > $window['service_start']) {
                $isOccupied = true;
                $conflictReason = 'Occupied / Reserved';
                break;
            }
            // Check transition buffer overlap
            if ($slotBufStartTs < $window['window_end'] && $slotBufEndTs > $window['window_start']) {
                $isBufferConflict = true;
                $conflictReason = '30-min Transition Buffer';
                break;
            }
        }
    }

    $available = (!$isPast && !$isOccupied && !$isBufferConflict);

    $generatedSlots[] = [
        'time' => $timeStr,
        'label' => date('g:i A', $slotStartTs) . ' - ' . date('g:i A', $slotEndTs),
        'start_display' => date('g:i A', $slotStartTs),
        'end_display' => date('g:i A', $slotEndTs),
        'available' => $available,
        'is_occupied' => $isOccupied,
        'is_buffer_conflict' => $isBufferConflict,
        'is_past' => $isPast,
        'reason' => $conflictReason ?: 'Available'
    ];
}

$availableCount = count(array_filter($generatedSlots, fn($s) => $s['available']));

echo json_encode([
    'success' => true,
    'date' => $dateStr,
    'formatted_date' => date('F j, Y (l)', strtotime($dateStr)),
    'duration_minutes' => $duration,
    'buffer_minutes' => $bufferMinutes,
    'total_slots' => count($generatedSlots),
    'available_slots_count' => $availableCount,
    'is_fully_booked' => ($availableCount === 0),
    'slots' => $generatedSlots,
    'occupied_intervals' => $occupiedIntervals
]);
