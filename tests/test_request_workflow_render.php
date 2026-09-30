<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/authentication.php';
$_SESSION['user_id'] = 1;
$_SESSION['fullname'] = 'Admin User';
$_SESSION['email'] = 'admin@example.com';
$_SESSION['role_keys'] = ['administrator'];
$_SESSION['role'] = 'admin';
$_SESSION['password_authenticated'] = true;
$_SESSION['fully_authenticated'] = true;
$_SESSION['mfa_verified'] = true;
$_SESSION['session_fingerprint'] = hash('sha256', authenticationUserAgent());

$_GET['id'] = 332;

ob_start();
try {
    chdir(__DIR__ . '/../admin');
    include __DIR__ . '/../admin/request-workflow.php';
    $output = ob_get_clean();
    echo "SUCCESS: rendered " . strlen($output) . " bytes\n";
    
    $checks = [
        'rw-hero-card' => 'Outer hero card container',
        'rw-hero-banner' => 'Dark hero header banner (#1e293b)',
        'rw-status-pill' => 'Lifecycle status soft-pill indicator',
        'rw-hero-tracking' => 'Tracking reference identifier',
        'rw-metadata-grid' => '4-column metadata grid',
        'PARISHIONER NAME' => 'Row 1 Col 1: Parishioner Name',
        'TRACKING REFERENCE' => 'Row 1 Col 2: Tracking Reference',
        'EMAIL ADDRESS' => 'Row 1 Col 3: Email Address',
        'CONTACT NUMBER' => 'Row 1 Col 4: Contact Number',
        'SERVICE NAME' => 'Row 2 Col 5: Service Name',
        'SERVICE TYPE' => 'Row 2 Col 6: Service Type',
        'CATEGORY' => 'Row 2 Col 7: Category label',
        'DATE REQUESTED' => 'Row 2 Col 8: Date Requested',
        'rw-app-drawer-toggle-bar' => 'Collapsible drawer toggle bar',
        'View Submitted Application Form' => 'Drawer toggle button text',
        'rw-review-card' => 'Operational review & action card',
        'REVIEW STATUS &amp; UPDATES' => 'Review card header title',
        'rw-review-split-grid' => 'Side-by-side 40/60 split grid',
        'CURRENT STATUS' => 'Current status dropdown label',
        'ADMIN RESPONSE / REMARKS' => 'Admin remarks textarea label',
        'Changing this updates parishioner tracking status.' => 'Status dropdown helper caption',
        'Sent in parishioner email &amp; portal notification.' => 'Admin response notification caption',
        'rw-action-toolbar' => 'Action toolbar',
        'Back to Requests' => 'Secondary back button',
        'Update Request' => 'Primary gold accent action button'
    ];
    
    $allPassed = true;
    foreach ($checks as $pattern => $label) {
        if (str_contains($output, $pattern)) {
            echo "[PASS] $label\n";
        } else {
            echo "[FAIL] $label (missing '$pattern')\n";
            $allPassed = false;
        }
    }
    
    if ($allPassed) {
        echo "\nALL REQUEST WORKFLOW RENDER ASSERTIONS PASSED!\n";
        exit(0);
    } else {
        echo "\nSOME ASSERTIONS FAILED!\n";
        exit(1);
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
