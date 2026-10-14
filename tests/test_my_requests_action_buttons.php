<?php
/**
 * Test Suite: My Requests Action Buttons & Download Certificate Backend Verification
 *
 * Validates:
 * 1. Certificate requests (type ends with "_certificate"):
 *    - completed + cert file issued => "Download Certificate"
 *    - otherwise => "Certificate not ready yet" (disabled)
 * 2. Blessings and sacramental services:
 *    - NEVER show "Download Certificate" or "Certificate not ready yet"
 *    - ONLY show "View Details"
 * 3. Every row keeps "View Details", duplicate "View" button is removed.
 * 4. Backend download-certificate.php 403 checks:
 *    - user ownership
 *    - type ends with "_certificate"
 *    - status is "completed"
 *    - certificate file exists
 * 5. Styling: gold download button, outlined view details, disabled style.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/helpers.php';

$failed = 0;
function testCheck(bool $condition, string $msg): void {
    global $failed;
    if ($condition) {
        echo "[PASS] $msg\n";
    } else {
        echo "[FAIL] $msg\n";
        $failed++;
    }
}

echo "=== TESTING VIEWS/USERS/MY-REQUESTS.PHP ROW RENDERING ===\n";

function renderRowAction(array $request): string {
    ob_start();
    $disp_status_lower = strtolower(trim((string)($request['status'] ?? '')));
    $is_completed = ($disp_status_lower === 'completed');
    $req_type_raw = strtolower(trim((string)($request['request_type'] ?? '')));
    $is_certificate_req = str_ends_with($req_type_raw, '_certificate');
    $has_cert_file = !empty($request['certificate_file_path']);
    ?>
    <td class="py-3 px-4 text-end" data-label="Action">
        <div class="d-inline-flex gap-1 align-items-center flex-wrap justify-content-end">
            <?php
            if ($is_certificate_req):
                if ($is_completed && $has_cert_file):
            ?>
                    <a href="download-certificate.php?request_id=<?php echo (int) $request['request_id']; ?>&download=1" class="btn btn-sm text-white px-2.5 py-1 fw-semibold d-inline-flex align-items-center gap-1" style="background: #C89B3C; border-color: #A97F24; border-radius: 8px;" title="Download Certificate">
                        <i class="fas fa-download"></i> Download Certificate
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2.5 py-1 fw-semibold d-inline-flex align-items-center gap-1" style="border-radius: 8px; opacity: 0.65; cursor: not-allowed;" disabled title="Certificate not ready yet">
                        <i class="fas fa-clock"></i> Certificate not ready yet
                    </button>
                <?php
                endif;
            endif;
            ?>
            <a href="view-request.php?id=<?php echo (int) $request['request_id']; ?>" class="btn btn-sm btn-outline-secondary px-3 py-1 fw-semibold" style="border-color: #E8E1D5; border-radius: 8px;">
                <i class="fas fa-eye me-1" style="color: #C89B3C;"></i> View Details
            </a>
        </div>
    </td>
    <?php
    return ob_get_clean();
}

// 1. Certificate request: completed + cert file issued
$row1 = renderRowAction([
    'request_id' => 101,
    'request_type' => 'baptismal_certificate',
    'status' => 'completed',
    'certificate_file_path' => 'uploads/request_requirements/cert_101.pdf',
]);
testCheck(strpos($row1, 'Download Certificate') !== false, 'Completed cert with file shows "Download Certificate"');
testCheck(strpos($row1, 'Certificate not ready yet') === false, 'Completed cert with file does NOT show "Certificate not ready yet"');
testCheck(strpos($row1, '> View<') === false && strpos($row1, '>View<') === false, 'Duplicate "View" button is removed');
testCheck(strpos($row1, 'View Details') !== false, 'Completed cert with file keeps "View Details"');
testCheck(strpos($row1, '#C89B3C') !== false, 'Download button has gold styling (#C89B3C)');

// 2. Certificate request: completed + NO cert file issued
$row2 = renderRowAction([
    'request_id' => 102,
    'request_type' => 'confirmation_certificate',
    'status' => 'completed',
    'certificate_file_path' => null,
]);
testCheck(strpos($row2, 'Download Certificate') === false, 'Completed cert without file does NOT show "Download Certificate"');
testCheck(strpos($row2, 'Certificate not ready yet') !== false, 'Completed cert without file shows "Certificate not ready yet"');
testCheck(strpos($row2, 'disabled') !== false, '"Certificate not ready yet" button is disabled');
testCheck(strpos($row2, 'View Details') !== false, 'Completed cert without file keeps "View Details"');

// 3. Certificate request: pending
$row3 = renderRowAction([
    'request_id' => 103,
    'request_type' => 'first_communion_certificate',
    'status' => 'pending',
    'certificate_file_path' => null,
]);
testCheck(strpos($row3, 'Download Certificate') === false, 'Pending cert does NOT show "Download Certificate"');
testCheck(strpos($row3, 'Certificate not ready yet') !== false, 'Pending cert shows "Certificate not ready yet"');
testCheck(strpos($row3, 'View Details') !== false, 'Pending cert keeps "View Details"');

// 4. Blessing request: completed
$row4 = renderRowAction([
    'request_id' => 104,
    'request_type' => 'house_blessing',
    'status' => 'completed',
    'certificate_file_path' => 'uploads/request_requirements/dummy.pdf',
]);
testCheck(strpos($row4, 'Download Certificate') === false, 'Completed house blessing NEVER shows "Download Certificate"');
testCheck(strpos($row4, 'Certificate not ready yet') === false, 'Completed house blessing NEVER shows "Certificate not ready yet"');
testCheck(strpos($row4, 'View Details') !== false, 'Completed house blessing shows "View Details"');

// 5. Blessing request: pending
$row5 = renderRowAction([
    'request_id' => 105,
    'request_type' => 'event_blessing',
    'status' => 'pending',
    'certificate_file_path' => null,
]);
testCheck(strpos($row5, 'Download Certificate') === false, 'Pending event blessing NEVER shows "Download Certificate"');
testCheck(strpos($row5, 'Certificate not ready yet') === false, 'Pending event blessing NEVER shows "Certificate not ready yet"');
testCheck(strpos($row5, 'View Details') !== false, 'Pending event blessing shows "View Details"');

// 6. Sacramental services: baptism_service, confirmation_service, first_communion_service
foreach (['baptism_service', 'confirmation_service', 'first_communion_service'] as $svc) {
    $rowSvc = renderRowAction([
        'request_id' => 106,
        'request_type' => $svc,
        'status' => 'completed',
        'certificate_file_path' => null,
    ]);
    testCheck(strpos($rowSvc, 'Download Certificate') === false, "Completed $svc NEVER shows \"Download Certificate\"");
    testCheck(strpos($rowSvc, 'Certificate not ready yet') === false, "Completed $svc NEVER shows \"Certificate not ready yet\"");
    testCheck(strpos($rowSvc, 'View Details') !== false, "Completed $svc shows \"View Details\"");
}

echo "\n=== TESTING FILE CONTENTS OF VIEWS/USERS/MY-REQUESTS.PHP ===\n";
$viewCode = file_get_contents($root . '/views/users/my-requests.php');
testCheck(strpos($viewCode, 'str_ends_with($req_type_raw, \'_certificate\')') !== false, 'my-requests.php checks type ends with _certificate');
testCheck(strpos($viewCode, 'download-certificate.php?request_id=<?php echo (int) $request[\'request_id\']; ?>&view=1') === false, 'Duplicate view link removed from view');
testCheck(strpos($viewCode, 'background: #C89B3C') !== false, 'Gold download button style present in view');

echo "\n=== TESTING DOWNLOAD-CERTIFICATE.PHP BACKEND LOGIC ===\n";
$downloadCode = file_get_contents($root . '/users/download-certificate.php');
testCheck(strpos($downloadCode, '!str_ends_with($req_type, \'_certificate\')') !== false, 'download-certificate.php validates type ends with _certificate');
testCheck(strpos($downloadCode, '$req_status !== \'completed\'') !== false, 'download-certificate.php validates status is completed');
testCheck(strpos($downloadCode, 'http_response_code(403)') !== false, 'download-certificate.php sets HTTP 403 on validation failure');
testCheck(strpos($downloadCode, 'exit(\'Access denied.') !== false, 'download-certificate.php exits on 403 rather than 302 redirecting');

echo "\n========================================================\n";
echo "SUMMARY: " . ($failed === 0 ? "ALL CHECKS PASSED!" : "$failed CHECKS FAILED!") . "\n";
echo "========================================================\n";

exit($failed === 0 ? 0 : 1);
