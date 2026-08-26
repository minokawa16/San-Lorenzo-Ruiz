<?php
$pages = [
    'baptism-records.php',
    'communion-records.php',
    'confirmation-records.php',
    'marriage-records.php',
    'funeral-records.php',
    'sacramental-import.php',
    'record-corrections.php'
];

foreach ($pages as $page) {
    session_name('TUGONSESSID');
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['roles'] = ['admin'];
    $_SESSION['email'] = 'tugonparish@gmail.com';
    $_SESSION['account_role'] = 'admin';
    $_SESSION['fully_authenticated'] = true;
    $_SESSION['last_activity'] = time();

    chdir(__DIR__ . '/../admin');
    ob_start();
    include $page;
    $out = ob_get_clean();

    $has_premium = strpos($out, 'class="premium-admin"') !== false ? 'YES' : 'NO';
    $has_shell = strpos($out, 'class="premium-admin-shell"') !== false ? 'YES' : 'NO';
    $has_content = strpos($out, 'class="premium-admin-content') !== false ? 'YES' : 'NO';
    $bytes = strlen($out);

    echo sprintf("%-28s | Bytes: %-6d | premium-admin: %s | shell: %s | content: %s\n", $page, $bytes, $has_premium, $has_shell, $has_content);
}
