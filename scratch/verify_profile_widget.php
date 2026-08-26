<?php
$cookie = __DIR__ . '/test_verify_cookie.txt';
if (file_exists($cookie)) unlink($cookie);
$base_url = 'https://tugon-web-production.up.railway.app';

// 1. Fetch CSRF token from login page
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $base_url . '/auth/login.php',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_SSL_VERIFYPEER => false
]);
$login_html = curl_exec($ch);
preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $login_html, $m);
$csrf = $m[1] ?? '';

// 2. Perform Login
curl_setopt_array($ch, [
    CURLOPT_URL => $base_url . '/auth/login.php',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf_token' => $csrf,
        'email' => 'tugonparish@gmail.com',
        'password' => 'Parishioner@123',
        'form_action' => 'login'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
curl_exec($ch);

// 3. Check Pages with GET
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$pages = [
    'Dashboard' => '/admin/dashboard.php',
    'Manage Parishioners' => '/admin/manage-users.php',
    'Sacramental Records' => '/admin/manage-records.php',
    'Schedule Calendar' => '/admin/manage-calendar.php',
    'Certificate Generator' => '/admin/certificate-generator.php',
    'Settings' => '/admin/settings.php'
];

echo "=== Admin Profile Widget Verification ===\n";
foreach ($pages as $label => $uri) {
    curl_setopt($ch, CURLOPT_URL, $base_url . $uri);
    $html = curl_exec($ch);

    $has_admin_name = strpos($html, 'parish-profile-name">Admin</span>') !== false || strpos($html, 'profile-name">Admin</span>') !== false;
    $has_admin_role = strpos($html, 'Administrator</span>') !== false;
    $has_avatar_a = strpos($html, 'parish-profile-avatar">A</span>') !== false || strpos($html, 'profile-avatar">A</span>') !== false;

    echo "[$label]\n";
    echo "  - Display Name is 'Admin': " . ($has_admin_name ? "PASSED" : "FAILED") . "\n";
    echo "  - Subtitle is 'Administrator': " . ($has_admin_role ? "PASSED" : "FAILED") . "\n";
    echo "  - Avatar Initial is 'A': " . ($has_avatar_a ? "PASSED" : "FAILED") . "\n";
}
curl_close($ch);
