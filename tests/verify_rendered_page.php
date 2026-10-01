<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';

$admin = $conn->query("SELECT id, fullname, email, role FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1")->fetch_assoc();
if (!$admin) {
    echo "No admin user found\n";
    exit(1);
}

$_SESSION['user_id'] = (int) $admin['id'];
$_SESSION['fullname'] = $admin['fullname'];
$_SESSION['email'] = $admin['email'];
$_SESSION['role'] = 'admin';
$_SESSION['fully_authenticated'] = true;
chdir(__DIR__ . '/../admin');
echo "=== VERIFYING RENDERED MANAGE REQUESTS PAGE ===\n";

// Test 1: Active service date
$_GET = ['service_date' => '2026-05-20'];
ob_start();
include __DIR__ . '/../admin/manage-requests.php';
$html = ob_get_clean();

$checks = [
    'Header "Requested Schedule"' => strpos($html, '<th>Requested Schedule</th>') !== false,
    'Header "Submitted" (renamed from Date)' => strpos($html, '<th>Submitted</th>') !== false,
    'Result summary bar displayed' => strpos($html, 'Showing') !== false && strpos($html, 'requests for') !== false,
    'Clear date link present' => strpos($html, 'Clear date') !== false,
    'Certificates exclusion note present' => strpos($html, 'Certificates are not shown because they have no service date.') !== false,
    'Calendar icon present inside input wrapper' => strpos($html, 'service-date-calendar-icon') !== false,
    'Quick chips Today / Tomorrow / This week present' => strpos($html, 'service-date-chip') !== false,
    'Calendar component script included' => strpos($html, 'admin-service-date-filter.js') !== false,
];

foreach ($checks as $title => $passed) {
    echo ($passed ? " [PASS] " : " [FAIL] ") . $title . "\n";
}

// Test 2: Empty date
$_GET = ['service_date' => '2099-01-01'];
ob_start();
include __DIR__ . '/../admin/manage-requests.php';
$html2 = ob_get_clean();
$emptyCheck = strpos($html2, 'No blessing or service requests for this day.') !== false;
echo ($emptyCheck ? " [PASS] " : " [FAIL] ") . 'Empty day message displayed on day with no requests' . "\n";

// Test 3: Invalid date handled safely
$_GET = ['service_date' => 'not-a-valid-date'];
ob_start();
include __DIR__ . '/../admin/manage-requests.php';
$html3 = ob_get_clean();
$pos = strpos($html3, 'not-a-valid-date');
if ($pos !== false) {
    echo "Found 'not-a-valid-date' around: " . substr($html3, max(0, $pos - 40), 100) . "\n";
}
$invalidCheck = strlen($html3) > 1000 && ($pos === false);
echo ($invalidCheck ? " [PASS] " : " [FAIL] ") . 'Invalid date handled safely and ignored' . "\n";

// Test 4: Category Certificates disables Service Date
$_GET = ['category' => 'certificate'];
ob_start();
include __DIR__ . '/../admin/manage-requests.php';
$html4 = ob_get_clean();
$certCheck = strpos($html4, 'disabled') !== false && strpos($html4, 'Not applicable to certificates') !== false;
echo ($certCheck ? " [PASS] " : " [FAIL] ") . 'Category Certificates disables Service Date field with hint' . "\n";

echo "=== VERIFICATION COMPLETE ===\n";
