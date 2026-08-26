<?php
$urls = [
    'Login Railway' => 'https://tugon-web-production.up.railway.app/auth/login.php',
    'Register Railway' => 'https://tugon-web-production.up.railway.app/auth/register.php',
    'Login Vercel' => 'https://tugon-parish-system.vercel.app/auth/login.php',
    'Register Vercel' => 'https://tugon-parish-system.vercel.app/auth/register.php'
];

echo "========================================\n";
echo " AUTH LOGO PARITY VERIFICATION\n";
echo "========================================\n";

foreach ($urls as $name => $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $has174 = strpos($html, '174px') !== false;
    $hasLogoImg = strpos($html, 'san-lorenzo-logo.png') !== false;
    echo sprintf("[%s] HTTP %d | Asset Included: %s\n", $name, $code, $hasLogoImg ? 'PASSED' : 'FAILED');
}

$css = file_get_contents('https://tugon-web-production.up.railway.app/assets/css/login-institutional.css');
echo "\n--- Live CSS Verification ---\n";
echo "174px Circular Logo in login-institutional.css: " . (strpos($css, '174px') !== false ? 'PASSED' : 'FAILED') . "\n";
echo "Gold Border (#c39b2a) in login-institutional.css: " . (strpos($css, '#c39b2a') !== false ? 'PASSED' : 'FAILED') . "\n";
echo "Circle Clip-Path in login-institutional.css: " . (strpos($css, 'clip-path: circle') !== false ? 'PASSED' : 'FAILED') . "\n";
