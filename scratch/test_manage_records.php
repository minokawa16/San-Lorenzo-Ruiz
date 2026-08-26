<?php
$cookie_file = __DIR__ . '/manage_records_cookie.txt';
if (file_exists($cookie_file)) unlink($cookie_file);

$base = 'http://localhost/ParishSystem';

// Login as admin
$ch = curl_init("$base/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_FOLLOWLOCATION => true
]);
$html = curl_exec($ch);

// Extract CSRF token
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches);
$csrf = $matches[1] ?? '';

curl_setopt_array($ch, [
    CURLOPT_URL => "$base/login.php",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'csrf_token' => $csrf,
        'identifier' => 'tugonparish@gmail.com',
        'password' => 'Parishioner@123'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
$res = curl_exec($ch);

// Fetch manage-records.php
curl_setopt_array($ch, [
    CURLOPT_URL => "$base/admin/manage-records.php",
    CURLOPT_POST => false,
    CURLOPT_FOLLOWLOCATION => true
]);
$records_html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $http_code\n";
echo "Has premium-admin class: " . (strpos($records_html, 'class="premium-admin"') !== false ? 'YES' : 'NO') . "\n";
echo "Has premium-admin-shell: " . (strpos($records_html, 'class="premium-admin-shell"') !== false ? 'YES' : 'NO') . "\n";
echo "Has premium-admin-content: " . (strpos($records_html, 'class="premium-admin-content') !== false ? 'YES' : 'NO') . "\n";
echo "Has admin-sidebar: " . (strpos($records_html, 'admin-sidebar') !== false ? 'YES' : 'NO') . "\n";
echo "Has Sacramental Records title: " . (strpos($records_html, 'Sacramental Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Baptism Records: " . (strpos($records_html, 'Baptism Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has First Communion Records: " . (strpos($records_html, 'First Communion Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Confirmation Records: " . (strpos($records_html, 'Confirmation Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Marriage Records: " . (strpos($records_html, 'Marriage Records') !== false ? 'YES' : 'NO') . "\n";
echo "Has Funeral Records: " . (strpos($records_html, 'Funeral Records') !== false ? 'YES' : 'NO') . "\n";
curl_close($ch);
