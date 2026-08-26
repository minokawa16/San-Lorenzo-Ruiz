<?php
$page = $argv[1] ?? 'manage-records.php';

session_name('TUGONSESSID');
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['roles'] = ['admin'];
$_SESSION['email'] = 'tugonparish@gmail.com';
$_SESSION['account_role'] = 'admin';
$_SESSION['fully_authenticated'] = true;
$_SESSION['last_activity'] = time();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/ParishSystem/admin/' . $page;

chdir(__DIR__ . '/../admin');
ob_start();
include $page;
$out = ob_get_clean();

$p = strpos($out, 'class="premium-admin"') !== false ? 'YES' : 'NO';
$s = strpos($out, 'class="premium-admin-shell"') !== false ? 'YES' : 'NO';
$c = strpos($out, 'class="premium-admin-content') !== false ? 'YES' : 'NO';

echo sprintf("%-25s | Bytes: %-6d | premium-admin: %s | shell: %s | content: %s\n", $page, strlen($out), $p, $s, $c);
