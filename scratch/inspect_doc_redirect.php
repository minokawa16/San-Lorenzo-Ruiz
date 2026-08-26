<?php
$cookie_file = __DIR__ . '/admin_doc_cookie.txt';
$ch = curl_init('http://localhost/ParishSystem/admin/view-valid-id.php?id=9&type=id');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_FOLLOWLOCATION => false,
]);
$res = curl_exec($ch);
$info = curl_getinfo($ch);
echo "HTTP code: " . $info['http_code'] . "\n";
echo "Redirect URL: " . ($info['redirect_url'] ?? 'none') . "\n";
echo "Content Type: " . ($info['content_type'] ?? 'none') . "\n";
echo "Body start:\n" . substr($res, 0, 300) . "\n";
