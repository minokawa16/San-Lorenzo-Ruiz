<?php
$ch = curl_init('https://tugon-web-production.up.railway.app/tools/prod_diag.php?token=tugon_secret_diag_2026&action=run_migration');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP {$code}:\n{$res}\n";

$ch2 = curl_init('https://tugon-web-production.up.railway.app/tools/prod_diag.php?token=tugon_secret_diag_2026&action=schema_audit');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30
]);
$res2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP {$code2}:\n{$res2}\n";
