<?php
$baseUrl = 'https://tugon-parish-system.vercel.app';
$cookieFileAdmin = __DIR__ . '/test_online_admin_cookie.txt';
$cookieFileUser = __DIR__ . '/test_online_user_cookie.txt';

function httpGet($url, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['status' => $status, 'url' => $finalUrl, 'body' => $body];
}

// 1. Admin Manage Records
$adminRecords = httpGet("{$baseUrl}/admin/manage-records.php", $cookieFileAdmin);
echo "1. Admin Sacramental Records Hub: HTTP {$adminRecords['status']} | URL: {$adminRecords['url']}\n";
echo "   Has shell: " . (strpos($adminRecords['body'], 'premium-admin-shell') !== false ? 'YES' : 'NO') . "\n";

// 2. Admin Baptism Records
$adminBaptism = httpGet("{$baseUrl}/admin/baptism-records.php", $cookieFileAdmin);
echo "2. Admin Baptism Records Page: HTTP {$adminBaptism['status']} | URL: {$adminBaptism['url']}\n";

// 3. Parishioner Profile
$userProfile = httpGet("{$baseUrl}/users/profile.php", $cookieFileUser);
echo "3. Parishioner Profile: HTTP {$userProfile['status']} | URL: {$userProfile['url']}\n";

// 4. Parishioner Dashboard
$userDash = httpGet("{$baseUrl}/users/index.php", $cookieFileUser);
echo "4. Parishioner Dashboard: HTTP {$userDash['status']} | URL: {$userDash['url']}\n";
