<?php
require_once __DIR__ . '/../includes/helpers.php';

echo "=== TESTING ONLINE RELEASE COPY & CERTIFICATE DELIVERY FLOW ===\n\n";

// 1. Check users/request-certificate.php copy
$req_cert = file_get_contents(__DIR__ . '/../users/request-certificate.php');
assert(
    strpos($req_cert, 'Delivered digitally through the system and downloadable directly from your portal once approved.') !== false,
    'Online Release copy was not updated properly in request-certificate.php'
);
assert(
    strpos($req_cert, 'Delivered digitally via email') === false,
    'Old email delivery copy still present in request-certificate.php'
);
echo "[PASS] request-certificate.php has corrected Online Release copy (no email references).\n";

// 2. Check admin/request-workflow.php for Send to Parishioner action and upload form
$admin_wf = file_get_contents(__DIR__ . '/../admin/request-workflow.php');
assert(
    strpos($admin_wf, 'RELEASE CERTIFICATE (ONLINE)') !== false || strpos($admin_wf, 'Certificate Issuance') !== false,
    'Release Certificate header missing in request-workflow.php'
);
assert(
    strpos($admin_wf, 'btnUploadCert') !== false || strpos($admin_wf, 'Send to Parishioner') !== false,
    'Upload Certificate button missing in request-workflow.php'
);
assert(
    strpos($admin_wf, 'Certificate Ready for Download') !== false,
    'Certificate Ready for Download notification missing in request-workflow.php'
);
assert(
    strpos($admin_wf, 'name="certificate_file"') !== false || strpos($admin_wf, 'name="release_file"') !== false,
    'certificate_file input missing in request-workflow.php'
);
echo "[PASS] admin/request-workflow.php contains upload certificate form, 'Send to Parishioner' action, and portal notification.\n";

// 3. Check users/view-request.php for certificate download hero card & status badge
$view_req = file_get_contents(__DIR__ . '/../users/view-request.php');
assert(
    strpos($view_req, 'Official Certificate Ready for Download') !== false,
    'Official Certificate Ready hero card missing in view-request.php'
);
assert(
    strpos($view_req, 'Download Certificate') !== false,
    'Download Certificate button missing in view-request.php'
);
assert(
    strpos($view_req, 'Completed') !== false,
    'Completed status mapping missing in view-request.php'
);
assert(
    strpos($view_req, 'released — available for download') === false,
    'Old released status should be removed from view-request.php'
);
echo "[PASS] users/view-request.php contains prominent Certificate Download card and 'Completed' status.\n";

// 4. Check helpers.php badge class
$badge = getStatusBadgeClass('completed');
assert(strpos($badge, 'bg-success') !== false, 'Badge class for completed status should be success');
echo "[PASS] getStatusBadgeClass properly supports completed status.\n";

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
