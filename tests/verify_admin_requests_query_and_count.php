<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';

echo "=== VERIFYING REQUESTS QUERY UNDER STRICT SQL MODE ===\n";

// Set strict SQL mode locally to mirror production
$conn->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$admin = $conn->query("SELECT id, fullname, email, role FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1")->fetch_assoc();
$_SESSION['user_id'] = (int) $admin['id'];
$_SESSION['fullname'] = $admin['fullname'];
$_SESSION['email'] = $admin['email'];
$_SESSION['role'] = 'admin';
$_SESSION['fully_authenticated'] = true;
$_SESSION['permissions'] = ['requests.manage', 'reservations.manage', 'admin.access'];

chdir(__DIR__ . '/../admin');

// 1. Run default view (no date filter)
$_GET = [];
ob_start();
include __DIR__ . '/../admin/manage-requests.php';
$html = ob_get_clean();

$hasErrorBanner = strpos($html, 'Error loading requests') !== false || strpos($html, 'is incompatible with sql_mode=only_full_group_by') !== false;
echo ($hasErrorBanner ? " [FAIL] " : " [PASS] ") . "Requests page loads with NO sql_mode error\n";

// 2. Verify row count matches
$reqCountRes = $conn->query("SELECT COUNT(*) AS c FROM requests WHERE deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM reservations linked_reservation WHERE linked_reservation.request_id=requests.request_id)");
$resCountRes = $conn->query("SELECT COUNT(*) AS c FROM reservations");
$expectedTotal = (int)$reqCountRes->fetch_assoc()['c'] + (int)$resCountRes->fetch_assoc()['c'];

echo " [INFO] Expected total active items in DB: $expectedTotal\n";
echo " [INFO] Table pagination total in manage-requests: " . (isset($total) ? $total : 'unset') . "\n";
$totalMatches = isset($total) && $total === $expectedTotal;
echo ($totalMatches ? " [PASS] " : " [FAIL] ") . "Pagination total exactly matches DB count without duplicates\n";

// 3. Test active date filter
$_GET = ['service_date' => '2026-05-20'];
ob_start();
include __DIR__ . '/../admin/manage-requests.php';
$htmlDate = ob_get_clean();

$hasDateError = strpos($htmlDate, 'Error loading requests') !== false;
echo ($hasDateError ? " [FAIL] " : " [PASS] ") . "Filtered by service_date=2026-05-20 loads with NO error\n";

// 4. Test calendar badge counts API
$_GET = ['month' => '2026-05'];
$escapedScript = addslashes(__DIR__ . '/../api/admin/requests/service-date-counts.php');
$root = addslashes(dirname(__DIR__));
$cmd = "php -r \"require_once '$root/includes/session.php'; require_once '$root/database/config.php'; require_once '$root/includes/helpers.php'; require_once '$root/includes/authentication.php'; \$_SESSION['user_id'] = " . (int)$admin['id'] . "; \$_SESSION['fully_authenticated'] = true; \$_SESSION['role'] = 'admin'; \$_SESSION['permissions'] = ['requests.manage', 'reservations.manage', 'admin.access']; \$_GET['month'] = '2026-05'; require '$escapedScript';\"";
$apiOutput = shell_exec($cmd);
$apiData = json_decode($apiOutput ?? '', true);

$apiSuccess = !empty($apiData['success']) && isset($apiData['counts']['2026-05-20']);
echo ($apiSuccess ? " [PASS] " : " [FAIL] ") . "Badge counts API executes cleanly and includes 2026-05-20 count\n";

echo "=== VERIFICATION COMPLETE ===\n";
