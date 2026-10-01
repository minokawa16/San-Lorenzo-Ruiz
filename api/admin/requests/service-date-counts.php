<?php
/**
 * API Endpoint: GET /api/admin/requests/service-date-counts.php
 * 
 * Aggregates requested service dates for blessing and sacramental service requests
 * for a visible calendar month, returning count badges for the calendar popup.
 *
 * Strict security: Admin / staff only.
 * Cache-Control: private, no-store
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../../../includes/session.php';
require_once __DIR__ . '/../../../database/config.php';
require_once __DIR__ . '/../../../includes/helpers.php';

// Verify authentication and administrator privileges
requireAdmin();
requirePermission('requests.manage');


$tz = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $tz);

// Validate month parameter (YYYY-MM)
$monthInput = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthInput)) {
    $monthInput = $now->format('Y-m');
}

$category = strtolower(trim((string) ($_GET['category'] ?? 'all')));
$status = strtolower(trim((string) ($_GET['status'] ?? 'all')));

// Certificates have no service dates; return empty counts immediately
if ($category === 'certificate') {
    echo json_encode([
        'success' => true,
        'month' => $monthInput,
        'counts' => (object) []
    ]);
    exit;
}

$monthStart = $monthInput . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// Request categories mapping
$blessingTypes = [
    'house_blessing', 'car_blessing', 'vehicle_blessing',
    'business_blessing', 'office_blessing', 'event_blessing', 'other_blessing'
];

$sacramentalRequestTypes = [
    'baptism_service', 'marriage_wedding_service', 'funeral_mass',
    'anointing_of_the_sick', 'patronal_fiesta'
];

$sacramentalReservationTypes = [
    'wedding', 'baptism', 'confirmation', 'burial', 'church_venue',
    'church_reservation', 'wedding_reservation', 'burial_reservation'
];

$allowedStatuses = ['pending', 'processing', 'completed', 'rejected'];

// Build dynamic WHERE clauses for requests
$reqTypes = [];
if ($category === 'blessing') {
    $reqTypes = $blessingTypes;
} elseif ($category === 'sacramental') {
    $reqTypes = $sacramentalRequestTypes;
} else {
    $reqTypes = array_merge($blessingTypes, $sacramentalRequestTypes);
}

$quotedReqTypes = "'" . implode("','", array_map(function($t) use ($conn) {
    return $conn->real_escape_string($t);
}, $reqTypes)) . "'";

$reqWhere = [
    "r.deleted_at IS NULL",
    "NOT EXISTS (SELECT 1 FROM reservations lr WHERE lr.request_id = r.request_id)",
    "r.request_type IN ($quotedReqTypes)"
];

if (in_array($status, $allowedStatuses, true)) {
    $escapedStatus = $conn->real_escape_string($status);
    $reqWhere[] = "r.status = '$escapedStatus'";
}

$reqWhereSql = implode(' AND ', $reqWhere);

// Build dynamic WHERE clauses for reservations
$includeReservations = ($category === 'all' || $category === 'sacramental');
$resWhere = ["1=1"];

if ($includeReservations) {
    $quotedResTypes = "'" . implode("','", array_map(function($t) use ($conn) {
        return $conn->real_escape_string($t);
    }, $sacramentalReservationTypes)) . "'";
    $resWhere[] = "r.reservation_type IN ($quotedResTypes)";

    if (in_array($status, $allowedStatuses, true)) {
        $escapedStatus = $conn->real_escape_string($status);
        $resWhere[] = "r.status = '$escapedStatus'";
    }
}

$resWhereSql = implode(' AND ', $resWhere);

$counts = [];

if ($includeReservations) {
    $sql = "
        SELECT service_day, SUM(cnt) AS total_count FROM (
            SELECT s_locks.slot_date AS service_day, COUNT(DISTINCT r.request_id) AS cnt
            FROM requests r
            JOIN schedule_slot_locks s_locks ON s_locks.source_type = 'request' AND s_locks.source_id = r.request_id AND s_locks.status = 'active'
            WHERE $reqWhereSql
              AND s_locks.slot_date >= ? AND s_locks.slot_date <= ?
            GROUP BY s_locks.slot_date

            UNION ALL

            SELECT r.event_date AS service_day, COUNT(*) AS cnt
            FROM reservations r
            WHERE $resWhereSql
              AND r.event_date >= ? AND r.event_date <= ?
            GROUP BY r.event_date
        ) sub
        GROUP BY service_day
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ssss', $monthStart, $monthEnd, $monthStart, $monthEnd);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $day = (string) $row['service_day'];
            if ($day !== '') {
                $counts[$day] = (int) $row['total_count'];
            }
        }
        $stmt->close();
    }
} else {
    // Blessings only
    $sql = "
        SELECT s_locks.slot_date AS service_day, COUNT(DISTINCT r.request_id) AS total_count
        FROM requests r
        JOIN schedule_slot_locks s_locks ON s_locks.source_type = 'request' AND s_locks.source_id = r.request_id AND s_locks.status = 'active'
        WHERE $reqWhereSql
          AND s_locks.slot_date >= ? AND s_locks.slot_date <= ?
        GROUP BY s_locks.slot_date
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $monthStart, $monthEnd);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $day = (string) $row['service_day'];
            if ($day !== '') {
                $counts[$day] = (int) $row['total_count'];
            }
        }
        $stmt->close();
    }
}

echo json_encode([
    'success' => true,
    'month' => $monthInput,
    'counts' => (object) $counts
], JSON_UNESCAPED_SLASHES);
