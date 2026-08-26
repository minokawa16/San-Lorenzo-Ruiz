<?php
$cookie = __DIR__ . '/vercel_cal_cookie.txt';
if (file_exists($cookie)) unlink($cookie);
$base = 'https://tugon-parish-system.vercel.app';

$ch = curl_init("$base/auth/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
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
    CURLOPT_URL => "$base/admin/manage-calendar.php",
    CURLOPT_POST => false,
    CURLOPT_FOLLOWLOCATION => true
]);
$cal_html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

echo "========================================\n";
echo " VERCEL CALENDAR VERIFICATION\n";
echo "========================================\n";
echo "HTTP Status: $http_code\n";
echo "Effective URL: $effective_url\n";
echo "Has 12-Column Grid: " . (strpos($cal_html, 'repeat(12, minmax(0, 1fr))') !== false ? 'PASSED' : 'FAILED') . "\n";
echo "Has Responsive col-span-3: " . (strpos($cal_html, 'span 3 / span 3') !== false ? 'PASSED' : 'FAILED') . "\n";
echo "Has Responsive col-span-9: " . (strpos($cal_html, 'span 9 / span 9') !== false ? 'PASSED' : 'FAILED') . "\n";
echo "Has Fixed Table Layout: " . (strpos($cal_html, 'table-layout: fixed') !== false ? 'PASSED' : 'FAILED') . "\n";
echo "Has Auto Content Height: " . (strpos($cal_html, "contentHeight: 'auto'") !== false ? 'PASSED' : 'FAILED') . "\n";
$script_count = substr_count($cal_html, '<script');
echo "Script Tag Count (Exactly 4 expected): " . ($script_count === 4 ? "PASSED ($script_count tags)" : "FAILED ($script_count tags)") . "\n";
echo "Has No Syntax-Breaking Duplicate Form: " . (substr_count($cal_html, 'id="eventForm"') === 1 ? 'PASSED (1 unique form)' : 'FAILED (duplicate forms found)') . "\n";
