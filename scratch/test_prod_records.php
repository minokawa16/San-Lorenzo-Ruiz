<?php
$cookie_file = __DIR__ . '/railway_records_cookie.txt';
if (file_exists($cookie_file)) unlink($cookie_file);

$base = 'https://tugon-web-production.up.railway.app';

// 1. Fetch auth/login.php for CSRF
$ch = curl_init("$base/auth/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 20
]);
$html = curl_exec($ch);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches);
$csrf = $matches[1] ?? '';

// 2. Login
curl_setopt_array($ch, [
    CURLOPT_URL => "$base/auth/login.php",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'csrf_token' => $csrf,
        'identifier' => 'tugonparish@gmail.com',
        'password' => 'Parishioner@123'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
$login_resp = curl_exec($ch);
$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
echo "Effective URL after login: $effective_url\n";

// 3. Request manage-records.php
curl_setopt_array($ch, [
    CURLOPT_URL => "$base/admin/manage-records.php",
    CURLOPT_POST => false,
    CURLOPT_FOLLOWLOCATION => true
]);
$records_html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effective_url_records = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

echo "Records HTTP Code: $http_code\n";
echo "Records Effective URL: $effective_url_records\n";
echo "Has premium-admin class: " . (strpos($records_html, 'class="premium-admin"') !== false ? 'YES' : 'NO') . "\n";
echo "Has premium-admin-shell: " . (strpos($records_html, 'class="premium-admin-shell"') !== false ? 'YES' : 'NO') . "\n";
echo "Has premium-admin-content: " . (strpos($records_html, 'class="premium-admin-content') !== false ? 'YES' : 'NO') . "\n";
echo "Has sacramental-hub-topbar: " . (strpos($records_html, 'sacramental-hub-topbar') !== false ? 'YES' : 'NO') . "\n";
echo "Has Baptism Records: " . (strpos($records_html, 'Baptism Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has First Communion Records: " . (strpos($records_html, 'First Communion Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Confirmation Records: " . (strpos($records_html, 'Confirmation Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Marriage Records: " . (strpos($records_html, 'Marriage Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Funeral Records: " . (strpos($records_html, 'Funeral Records') !== false ? 'YES' : 'NO') . "\n";

curl_close($ch);
