<?php
$baseUrl = 'https://tugon-parish-system.vercel.app';
$cookieFile = __DIR__ . '/test_admin_records_cookie.txt';

function httpReq($url) {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['status' => $status, 'url' => $finalUrl, 'body' => $body];
}

$pages = [
    'baptism-records.php',
    'communion-records.php',
    'confirmation-records.php',
    'marriage-records.php',
    'funeral-records.php',
    'sacramental-import.php',
    'record-corrections.php'
];

foreach ($pages as $p) {
    $resp = httpReq("{$baseUrl}/admin/{$p}");
    $body = $resp['body'];
    $hasPremium = strpos($body, 'class="premium-admin"') !== false ? 'YES' : 'NO';
    $hasShell = strpos($body, 'class="premium-admin-shell"') !== false ? 'YES' : 'NO';
    $hasContent = strpos($body, 'class="premium-admin-content') !== false ? 'YES' : 'NO';
    echo sprintf("%-25s | Code: %d | premium-admin: %s | shell: %s | content: %s\n", $p, $resp['status'], $hasPremium, $hasShell, $hasContent);
}
