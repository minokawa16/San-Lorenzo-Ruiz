<?php
$baseUrl = 'https://tugon-parish-system.vercel.app';
$cookieFile = __DIR__ . '/test_admin_records_cookie.txt';
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

// 2. Login as Admin
$loginResp = httpReq("{$baseUrl}/auth/login.php", 'POST', [
    '_csrf_token' => $csrfToken,
    'csrf_token' => $csrfToken,
    'email' => 'tugonparish@gmail.com',
    'password' => 'Parishioner@123',
    'form_action' => 'login'
]);

echo "Login status: {$loginResp['status']} | Final URL: {$loginResp['url']}\n";

// 3. Access manage-records.php
$recordsResp = httpReq("{$baseUrl}/admin/manage-records.php");
echo "Manage Records status: {$recordsResp['status']} | URL: {$recordsResp['url']}\n";

$body = $recordsResp['body'];
$hasPremium = strpos($body, 'class="premium-admin"') !== false;
$hasShell = strpos($body, 'class="premium-admin-shell"') !== false;
$hasContent = strpos($body, 'class="premium-admin-content') !== false;
$hasHubTopbar = strpos($body, 'sacramental-hub-topbar') !== false;
$hasBaptism = strpos($body, 'Baptism Records') !== false;
$hasCommunion = strpos($body, 'First Communion Records') !== false;
$hasConfirmation = strpos($body, 'Confirmation Records') !== false;
$hasMarriage = strpos($body, 'Marriage Records') !== false;
$hasFuneral = strpos($body, 'Funeral Records') !== false;

echo "Has premium-admin: " . ($hasPremium ? "YES" : "NO") . "\n";
echo "Has premium-admin-shell: " . ($hasShell ? "YES" : "NO") . "\n";
echo "Has premium-admin-content: " . ($hasContent ? "YES" : "NO") . "\n";
echo "Has sacramental-hub-topbar: " . ($hasHubTopbar ? "YES" : "NO") . "\n";
echo "Has Baptism Records: " . ($hasBaptism ? "YES" : "NO") . "\n";
echo "Has First Communion Records: " . ($hasCommunion ? "YES" : "NO") . "\n";
echo "Has Confirmation Records: " . ($hasConfirmation ? "YES" : "NO") . "\n";
echo "Has Marriage Records: " . ($hasMarriage ? "YES" : "NO") . "\n";
echo "Has Funeral Records: " . ($hasFuneral ? "YES" : "NO") . "\n";
