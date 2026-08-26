<?php
session_name('TUGONSESSID');
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['roles'] = ['admin'];
$_SESSION['email'] = 'tugonparish@gmail.com';
$_SESSION['account_role'] = 'admin';
$_SESSION['fully_authenticated'] = true;
$_SESSION['last_activity'] = time();

chdir(__DIR__ . '/../admin');
ob_start();
include 'manage-records.php';
$out = ob_get_clean();

echo "Rendered length: " . strlen($out) . " bytes\n";
echo "Has premium-admin: " . (strpos($out, 'class="premium-admin"') !== false ? 'YES' : 'NO') . "\n";
echo "Has premium-admin-shell: " . (strpos($out, 'class="premium-admin-shell"') !== false ? 'YES' : 'NO') . "\n";
echo "Has sacramental-hub-topbar: " . (strpos($out, 'sacramental-hub-topbar') !== false ? 'YES' : 'NO') . "\n";
echo "Has Baptism Records: " . (strpos($out, 'Baptism Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has First Communion Records: " . (strpos($out, 'First Communion Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Confirmation Records: " . (strpos($out, 'Confirmation Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Marriage Records: " . (strpos($out, 'Marriage Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Funeral Records: " . (strpos($out, 'Funeral Records') !== false ? 'YES' : 'NO') . "\n";
