<?php
// Test HTTP endpoint with admin login
$cookie_file = __DIR__ . '/admin_doc_cookie.txt';
if (file_exists($cookie_file)) unlink($cookie_file);

// 1. Login as admin
$ch = curl_init('http://localhost/ParishSystem/auth/login.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_FOLLOWLOCATION => true,
]);
$login_page = curl_exec($ch);
preg_match('/name="csrf_token" value="([^"]+)"/', $login_page, $m);
$csrf = $m[1] ?? '';

curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_token' => $csrf,
    'identifier' => 'tugonparish@gmail.com',
    'password' => 'Parishioner@123',
]));
curl_exec($ch);

// 2. Request view-valid-id.php?id=9&type=id
curl_setopt($ch, CURLOPT_URL, 'http://localhost/ParishSystem/admin/view-valid-id.php?id=9&type=id');
curl_setopt($ch, CURLOPT_HTTPGET, true);
$doc_content = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

echo "HTTP Code: $http_code\n";
echo "Content-Type: $content_type\n";
echo "Content Length: " . strlen($doc_content) . " bytes\n";
echo "Sample output: " . substr($doc_content, 0, 80) . "\n";

// 3. Request missing document (id=5)
curl_setopt($ch, CURLOPT_URL, 'http://localhost/ParishSystem/admin/view-valid-id.php?id=5&type=face');
$missing_content = curl_exec($ch);
$http_code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type2 = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
echo "\n--- Missing Document Test (User 5, face) ---\n";
echo "HTTP Code: $http_code2\n";
echo "Content-Type: $content_type2\n";
echo "Is SVG: " . (strpos($missing_content, '<svg') !== false ? "YES" : "NO") . "\n";
