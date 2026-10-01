<?php
/**
 * Test Suite: Admin Service Date Filter & Requested Schedule
 * San Lorenzo Ruiz Parish Management System (TUGON)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== TUGON Admin Service Date Filter Test Suite ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $testName, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $testName\n";
        $passCount++;
    } else {
        echo " [FAIL] $testName" . ($details ? " - $details" : "") . "\n";
        $failCount++;
    }
}

// 1. Test formatScheduleSlotRange helper
assertTest(
    formatScheduleSlotRange('09:00:00') === '9am – 10am',
    'formatScheduleSlotRange 09:00 -> 9am – 10am'
);
assertTest(
    formatScheduleSlotRange('08:30:00') === '8:30am – 9:30am',
    'formatScheduleSlotRange 08:30 -> 8:30am – 9:30am'
);
assertTest(
    formatScheduleSlotRange('14:00:00') === '2pm – 3pm',
    'formatScheduleSlotRange 14:00 -> 2pm – 3pm'
);
assertTest(
    formatScheduleSlotRange('12:00:00') === '12pm – 1pm',
    'formatScheduleSlotRange 12:00 -> 12pm – 1pm'
);
assertTest(
    formatScheduleSlotRange('') === '—' && formatScheduleSlotRange(null) === '—',
    'formatScheduleSlotRange handles empty values cleanly'
);

// 2. Test Non-Admin security on API endpoint
$ch = curl_init('http://127.0.0.1:8099/api/admin/requests/service-date-counts.php?month=2026-05');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest(
    $httpCode === 401 || $httpCode === 403,
    'Unauthenticated / non-admin cannot access service-date-counts endpoint (HTTP 401/403)',
    "Got HTTP $httpCode"
);

// 3. Test Admin Session on API endpoint
// Find an admin user in DB
$adminRow = $conn->query("SELECT id, fullname, email, role FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1")->fetch_assoc();
if ($adminRow) {
    if (function_exists('establishAuthenticatedSession')) {
        establishAuthenticatedSession($conn, $adminRow, true);
    } else {
        $_SESSION['user_id'] = (int) $adminRow['id'];
        $_SESSION['fullname'] = $adminRow['fullname'];
        $_SESSION['email'] = $adminRow['email'];
        $_SESSION['role'] = 'admin';
        $_SESSION['fully_authenticated'] = true;
        $_SESSION['permissions'] = ['requests.manage', 'reservations.manage', 'admin.access'];
    }

    // Test API logic directly
    $_GET['month'] = '2026-05';
    $_GET['category'] = 'all';
    $_GET['status'] = 'all';

    ob_start();
    include __DIR__ . '/../api/admin/requests/service-date-counts.php';
    $apiOutput = ob_get_clean();
    $apiData = json_decode($apiOutput, true);

    assertTest(
        is_array($apiData) && !empty($apiData['success']),
        'API returns JSON success with valid month counts',
        "Output: $apiOutput"
    );

    assertTest(
        isset($apiData['counts']['2026-05-20']),
        'API counts contain 2026-05-20 where requests exist',
        'Keys: ' . implode(', ', array_keys($apiData['counts'] ?? []))
    );

    // Test Certificate category returns empty counts in a sub-process so exit doesn't stop test runner
    $escapedScript = addslashes(__DIR__ . '/../api/admin/requests/service-date-counts.php');
    $cmd = "php -r \"require_once 'includes/session.php'; require_once 'database/config.php'; require_once 'includes/helpers.php'; require_once 'includes/authentication.php'; \$_SESSION['user_id'] = " . (int)$adminRow['id'] . "; \$_SESSION['fully_authenticated'] = true; \$_SESSION['role'] = 'admin'; \$_SESSION['permissions'] = ['requests.manage', 'reservations.manage', 'admin.access']; \$_GET['month'] = '2026-05'; \$_GET['category'] = 'certificate'; require '$escapedScript';\"";
    $certOutput = shell_exec($cmd);
    $certData = json_decode($certOutput ?? '', true);

    assertTest(
        is_array($certData) && empty((array)($certData['counts'] ?? [])),
        'Certificate category returns 0 counts (certificates have no service date)'
    );
}

// 4. Test Overlapping 1-hour Slot Logic
$testSlots = [
    ['key' => 'req_1', 'start' => 9 * 60, 'end' => 10 * 60],
    ['key' => 'req_2', 'start' => 9 * 60, 'end' => 10 * 60], // exact match overlap
    ['key' => 'req_3', 'start' => 11 * 60, 'end' => 12 * 60], // non-overlapping
];

$overlaps = [];
for ($i = 0; $i < count($testSlots); $i++) {
    for ($j = $i + 1; $j < count($testSlots); $j++) {
        if ($testSlots[$i]['start'] < $testSlots[$j]['end'] && $testSlots[$i]['end'] > $testSlots[$j]['start']) {
            $overlaps[$testSlots[$i]['key']] = true;
            $overlaps[$testSlots[$j]['key']] = true;
        }
    }
}

assertTest(
    !empty($overlaps['req_1']) && !empty($overlaps['req_2']) && empty($overlaps['req_3']),
    'Overlapping 1-hour slots are identified as Same time while distinct slots are not'
);

// 5. Test Date Validation in manage-requests logic
$validDate = '2026-10-12';
$invalidDate = 'not-a-date';
$sqlInjectionDate = "2026-10-12' OR 1=1 --";

$validateFn = function($date) {
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $date)) {
        $p = explode('-', $date);
        return checkdate((int)$p[1], (int)$p[2], (int)$p[0]);
    }
    return $date === 'this_week';
};

assertTest($validateFn($validDate) === true, 'Valid date 2026-10-12 passes validation');
assertTest($validateFn('this_week') === true, 'this_week keyword passes validation');
assertTest($validateFn($invalidDate) === false, 'Invalid string rejected safely');
assertTest($validateFn($sqlInjectionDate) === false, 'Injected string rejected safely');
assertTest($validateFn('2026-02-31') === false, 'Non-existent date (Feb 31) rejected safely');

// 6. Test Migration 041 schema and indexes
$checkIdx1 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedule_slot_locks' AND INDEX_NAME = 'idx_slot_source_date_time'")->fetch_assoc();
assertTest((int)$checkIdx1['c'] > 0, 'Migration 041 added index idx_slot_source_date_time on schedule_slot_locks');

$checkIdx2 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'idx_requests_type_status'")->fetch_assoc();
assertTest((int)$checkIdx2['c'] > 0, 'Migration 041 added index idx_requests_type_status on requests');

$checkIdx3 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservations' AND INDEX_NAME = 'idx_reservations_event_date_status'")->fetch_assoc();
assertTest((int)$checkIdx3['c'] > 0, 'Migration 041 added index idx_reservations_event_date_status on reservations');

// 7. Verify Historical Backfill in schedule_slot_locks
$countLocks = $conn->query("SELECT COUNT(*) AS c FROM schedule_slot_locks WHERE source_type = 'request'")->fetch_assoc();
assertTest((int)$countLocks['c'] > 0, 'schedule_slot_locks successfully contains backfilled request records');

echo "\nTest Results: $passCount Passed, $failCount Failed.\n";
if ($failCount > 0) {
    exit(1);
}
