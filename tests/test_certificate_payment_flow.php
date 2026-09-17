<?php
require_once __DIR__ . '/../includes/helpers.php';

echo "=== TESTING CERTIFICATE PAYMENT & RELEASE FLOW ===\n\n";

// 1. Static file assertions
$req_cert_content = file_get_contents(__DIR__ . '/../users/request-certificate.php');
$view_req_content = file_get_contents(__DIR__ . '/../users/view-request.php');

assert(strpos($req_cert_content, 'Payment &amp; Release') !== false || strpos($req_cert_content, 'Payment & Release') !== false, 'Step 5 title missing in request-certificate.php');
assert(strpos($req_cert_content, 'payment_method_gcash') !== false, 'GCash payment option missing in request-certificate.php');
assert(strpos($req_cert_content, 'payment_method_cash') !== false, 'Cash payment option missing in request-certificate.php');
assert(strpos($req_cert_content, 'release_method_online') !== false, 'Online release option missing in request-certificate.php');
assert(strpos($req_cert_content, 'release_method_walkin') !== false, 'Walk-in release option missing in request-certificate.php');
assert(strpos($req_cert_content, 'receipt_file') !== false, 'Receipt file upload missing in request-certificate.php');
assert(strpos($req_cert_content, 'data-copy-gcash') !== false, 'Copy GCash button missing in request-certificate.php');

echo "[PASS] request-certificate.php contains all Step 5 Payment & Release controls\n";

assert(strpos($view_req_content, 'action="submit_payment"') === false, 'submit_payment form still exists in view-request.php');
assert(strpos($view_req_content, '<input type="hidden" name="action" value="submit_payment">') === false, 'submit_payment input still exists in view-request.php');
assert(strpos($view_req_content, 'Submit Payment Receipt') === false, 'Submit Payment Receipt card still exists in view-request.php');
assert(strpos($view_req_content, 'Payment Information') !== false, 'Payment Information section missing in view-request.php');

echo "[PASS] view-request.php has submit_payment form removed and Payment Information section preserved\n";

// 2. Test helpers.php functions exist and have correct signatures
$reflector = new ReflectionFunction('createRequestPayment');
$params = $reflector->getParameters();
assert(count($params) >= 8, 'createRequestPayment parameter count unexpected');
assert($params[4]->getName() === 'payment_method', '5th param should be payment_method');

echo "[PASS] createRequestPayment function signature verified\n";

echo "\nALL STATIC & SIGNATURE TESTS PASSED SUCCESSFULLY!\n";

