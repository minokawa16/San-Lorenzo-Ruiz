<?php
$cookie = __DIR__ . '/test_dash_cookie.txt';
if (file_exists($cookie)) unlink($cookie);
$base = 'https://tugon-web-production.up.railway.app';

$ch = curl_init("$base/auth/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0'
]);
$html = curl_exec($ch);
preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $html, $m);
$csrf = $m[1] ?? '';

curl_setopt_array($ch, [
    CURLOPT_URL => "$base/auth/login.php",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf_token' => $csrf,
        'identifier' => 'tugonparish@gmail.com',
        'password' => 'Parishioner@123',
        'form_action' => 'login'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
curl_exec($ch);

curl_setopt_array($ch, [
    CURLOPT_URL => "$base/admin/dashboard.php",
    CURLOPT_POST => false,
    CURLOPT_FOLLOWLOCATION => true
]);
$dash_html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

echo "========================================\n";
echo " DASHBOARD DIRECT VERIFICATION\n";
echo "========================================\n";
echo "HTTP Status: $http_code\n";
echo "Effective URL: $effective_url\n";
echo "Content Length: " . strlen($dash_html) . " bytes\n";
echo "Has KPI Cards: " . (strpos($dash_html, 'kpi-card') !== false ? 'YES' : 'NO') . "\n";
echo "Has Quick Actions: " . (strpos($dash_html, 'Quick Actions') !== false ? 'YES' : 'NO') . "\n";
echo "Has Smart Search: " . (strpos($dash_html, 'adminSmartSearch') !== false ? 'YES' : 'NO') . "\n";
echo "Has Recent Requests: " . (strpos($dash_html, 'Recent Service Requests') !== false ? 'YES' : 'NO') . "\n";

// Also test Vercel URL
curl_setopt($ch, CURLOPT_URL, "https://tugon-parish-system.vercel.app/admin/dashboard.php");
$vercel_html = curl_exec($ch);
$vercel_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "\n--- Vercel Proxy Test ---\n";
echo "Vercel HTTP Status: $vercel_code\n";
echo "Vercel Content Length: " . strlen($vercel_html) . " bytes\n";
echo "Vercel Has KPI Cards: " . (strpos($vercel_html, 'kpi-card') !== false ? 'YES' : 'NO') . "\n";
