<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['admin_logged_in'] = true;
$_SESSION['logged_in'] = true;
$_SESSION['permissions'] = ['registrations.verify', 'users.view'];

// Test User ID 9 (has uploaded front ID)
$_GET['id'] = 9;
$_GET['type'] = 'id';

register_shutdown_function(function() {
    $out = ob_get_contents();
    echo "\n=== SHUTDOWN HOOK ===\n";
    echo "Total bytes sent: " . strlen($out) . "\n";
    echo "First 50 bytes: " . bin2hex(substr($out, 0, 50)) . "\n";
    echo "Is PNG header: " . (strpos($out, "\x89PNG") === 0 ? "YES" : "NO") . "\n";
    echo "Is SVG header: " . (strpos($out, "<svg") !== false ? "YES" : "NO") . "\n";
});

include __DIR__ . '/../admin/view-valid-id.php';
