<?php
$cookie = __DIR__ . '/railway_auth_cookie.txt';
$base_url = 'https://tugon-web-production.up.railway.app';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_URL => $base_url . '/admin/manage-users.php'
]);
$html = curl_exec($ch);
curl_close($ch);

preg_match('/<button[^>]*class="[^"]*profile-chip-btn[^"]*"[^>]*>.*?<\/button>/s', $html, $matches);
echo "Profile Chip Button HTML on /admin/manage-users.php:\n";
echo $matches[0] ?? "Not found\n";
