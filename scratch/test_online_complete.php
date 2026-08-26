<?php
$baseUrl = 'https://tugon-parish-system.vercel.app';
$cookieFileAdmin = __DIR__ . '/test_online_admin_cookie.txt';
$cookieFileUser = __DIR__ . '/test_online_user_cookie.txt';
if (file_exists($cookieFileAdmin)) unlink($cookieFileAdmin);
if (file_exists($cookieFileUser)) unlink($cookieFileUser);

function httpReq($url, $cookieFile, $method = 'GET', $data = null) {
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

echo "=== VERIFYING LIVE ONLINE SYSTEM ON VERCEL & RAILWAY ===\n\n";

// 1. Health check
$health = httpReq("{$baseUrl}/healthz.php", $cookieFileAdmin);
echo "1. Live Healthz: HTTP {$health['status']} | Content: " . trim(substr($health['body'], 0, 100)) . "\n";

// 2. Admin Login
$loginPage = httpReq("{$baseUrl}/auth/login.php", $cookieFileAdmin);
preg_match('/name="(_csrf_token|csrf_token)"\s+value="([^"]+)"/', $loginPage['body'], $m);
$csrf = $m[2] ?? '';

$adminLogin = httpReq("{$baseUrl}/auth/login.php", $cookieFileAdmin, 'POST', [
    '_csrf_token' => $csrf,
    'csrf_token' => $csrf,
    'email' => 'tugonparish@gmail.com',
    'password' => 'Parishioner@123',
    'form_action' => 'login'
]);
echo "2. Online Admin Login: HTTP {$adminLogin['status']} | Final URL: {$adminLogin['url']}\n";
echo "   Admin Login Success: " . (strpos($adminLogin['url'], 'admin/dashboard.php') !== false ? 'YES' : 'NO') . "\n";

// 3. Parishioner Login
$loginPage2 = httpReq("{$baseUrl}/auth/login.php", $cookieFileUser);
preg_match('/name="(_csrf_token|csrf_token)"\s+value="([^"]+)"/', $loginPage2['body'], $m2);
$csrf2 = $m2[2] ?? '';

$userLogin = httpReq("{$baseUrl}/auth/login.php", $cookieFileUser, 'POST', [
    '_csrf_token' => $csrf2,
    'csrf_token' => $csrf2,
    'email' => '09635866550',
    'password' => 'Reymark@123',
    'form_action' => 'login'
]);
echo "3. Online Parishioner Login: HTTP {$userLogin['status']} | Final URL: {$userLogin['url']}\n";
echo "   Parishioner Login Success: " . (strpos($userLogin['url'], 'users/index.php') !== false ? 'YES' : 'NO') . "\n";

// 4. Schema audit via prod_diag.php
$schemaReq = httpReq("https://tugon-web-production.up.railway.app/tools/prod_diag.php?token=tugon_secret_diag_2026&action=schema_audit", $cookieFileAdmin);
echo "\n4. Online Schema Audit (Railway MySQL):\n";
echo $schemaReq['body'] . "\n";
