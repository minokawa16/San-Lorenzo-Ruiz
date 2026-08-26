<?php
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['admin_logged_in'] = true;
$_SESSION['logged_in'] = true;

// Mock permissions
$_SESSION['permissions'] = ['registrations.verify', 'users.view'];

$_GET['id'] = 9;
$_GET['type'] = 'id';

ob_start();
include __DIR__ . '/../admin/view-valid-id.php';
$output = ob_get_clean();

echo "Response Length: " . strlen($output) . " bytes\n";
echo "First 100 chars: " . substr($output, 0, 100) . "\n";
