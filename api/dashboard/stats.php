<?php
/**
 * Personal Dashboard Stats API Endpoint
 * Returns real-time, user-scoped counts for personal dashboard stat cards.
 * Cached NEVER at edge or browser.
 */

// Critical cache-control headers as required by Vercel/Railway architecture
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../database/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

initSession();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required',
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

$user_id = getCurrentUserId();
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid user session',
        'code' => 'INVALID_SESSION'
    ]);
    exit;
}

$status_map = [
    'submitted'           => 'pending',
    'pending'             => 'pending',
    'requirements_review' => 'pending',
    'under_review'        => 'pending',
    'needs_information'   => 'pending',
    'payment_required'    => 'pending',
    'payment_review'      => 'pending',
    'draft'               => 'pending',
    'approved'            => 'processing',
    'processing'          => 'processing',
    'scheduled'           => 'processing',
    'ready_for_release'   => 'ready_to_download',
    'completed'           => 'completed',
    'rejected'            => 'rejected',
    'cancelled'           => 'cancelled',
];

$personal_stats = [
    'total'                 => 0,
    'pending'               => 0,
    'processing'            => 0,
    'completed'             => 0,
    'rejected'              => 0,
    'ready_to_download'     => 0,
    'upcoming_reservations' => 0,
    'parish_announcements'  => 0,
];

// 1. Single aggregated prepared query for request buckets
$stmt = $conn->prepare(
    'SELECT status, COUNT(*) AS cnt FROM requests WHERE user_id = ? AND deleted_at IS NULL GROUP BY status'
);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $raw   = strtolower(trim((string) $row['status']));
        $cnt   = intval($row['cnt']);
        $personal_stats['total'] += $cnt;
        $bucket = $status_map[$raw] ?? 'pending';
        if (array_key_exists($bucket, $personal_stats)) {
            $personal_stats[$bucket] += $cnt;
        }
    }
    $stmt->close();
}

// 2. Upcoming reservation count (this user, future dates)
$stmt = $conn->prepare(
    'SELECT COUNT(*) AS cnt FROM reservations WHERE user_id = ? AND event_date >= CURDATE() AND status != \'cancelled\''
);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $personal_stats['upcoming_reservations'] = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
}

// 3. Active parish announcements (public count)
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM announcements
     WHERE status = 'active' AND deleted_at IS NULL
       AND (scheduled_at IS NULL OR scheduled_at <= NOW())
       AND (expiry_date IS NULL OR expiry_date >= NOW())"
);
if ($stmt) {
    $stmt->execute();
    $personal_stats['parish_announcements'] = intval($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'stats' => $personal_stats,
    'timestamp' => time()
], JSON_UNESCAPED_SLASHES);
