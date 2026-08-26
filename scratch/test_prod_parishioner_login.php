<?php
$baseUrl = 'https://tugon-parish-system.vercel.app';
$cookieFile = __DIR__ . '/test_prod_parishioner_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

function httpReq($url, $method = 'GET', $data = null) {
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

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['status' => $status, 'url' => $finalUrl, 'body' => $body];
}

// 1. Fetch Login
$resp = httpReq("{$baseUrl}/auth/login.php");
preg_match('/name="(_csrf_token|csrf_token)"\s+value="([^"]+)"/', $resp['body'], $matches);
$csrfToken = $matches[2] ?? '';

// 2. Login as Parishioner
$loginResp = httpReq("{$baseUrl}/auth/login.php", 'POST', [
    '_csrf_token' => $csrfToken,
    'csrf_token' => $csrfToken,
    'email' => '09635866550',
    'password' => 'Reymark@123',
    'form_action' => 'login'
]);

echo "Parishioner Production Login Status: {$loginResp['status']} | Final URL: {$loginResp['url']}\n";
$isDashboard = strpos($loginResp['url'], 'dashboard.php') !== false;
echo "Parishioner Login Authenticated Successfully: " . ($isDashboard ? "YES" : "NO") . "\n";
