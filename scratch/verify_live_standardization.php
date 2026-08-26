<?php
/**
 * Test Live Header Standardization
 */
$admin_email = 'tugonparish@gmail.com';
$admin_pass = 'Parishioner@123';
$cookie_file = __DIR__ . '/railway_auth_test_cookie.txt';
$base_url = 'https://tugon-web-production.up.railway.app';

// 1. Authenticate
$ch = curl_init("$base_url/auth/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$login_page = curl_exec($ch);

preg_match('/name="csrf_token"\s+value="([^"]+)"/', $login_page, $m);
$csrf = $m[1] ?? '';

curl_setopt_array($ch, [
    CURLOPT_URL => "$base_url/auth/login.php",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'csrf_token' => $csrf,
        'email' => $admin_email,
        'password' => $admin_pass
    ]),
]);
$resp = curl_exec($ch);

// 2. Test several key pages for standard components
$pages = [
    'Dashboard' => '/admin/dashboard.php',
    'Manage Parishioners' => '/admin/manage-parishioners.php',
    'Manage Requests' => '/admin/manage-requests.php',
    'Sacramental Records' => '/admin/manage-records.php',
    'Baptism Records' => '/admin/baptism-records.php',
    'Settings' => '/admin/settings.php',
    'Archives' => '/admin/archives.php',
    'Verify Registrations' => '/admin/verify-registrations.php'
];

echo "=== Verification Results ===\n";
foreach ($pages as $label => $uri) {
    curl_setopt($ch, CURLOPT_URL, $base_url . $uri);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $has_top_nav = strpos($html, 'parish-top-nav-bar') !== false;
    $has_badge_icon = strpos($html, 'parish-nav-badge-icon') !== false;
    $has_profile_pill = strpos($html, 'parish-profile-pill-btn') !== false;
    $has_back_link = strpos($html, 'parish-back-link') !== false;
    $has_section_title = strpos($html, 'parish-section-title') !== false;
    $has_gold_underline = strpos($html, 'parish-gold-underline') !== false;

    echo "[$label] (HTTP $http_code)\n";
    echo "  - Top Nav Bar: " . ($has_top_nav ? "OK" : "MISSING") . "\n";
    echo "  - Nav Badge Icon: " . ($has_badge_icon ? "OK" : "MISSING") . "\n";
    echo "  - Profile Pill: " . ($has_profile_pill ? "OK" : "MISSING") . "\n";
    echo "  - Back Link: " . ($has_back_link ? "OK" : "N/A") . "\n";
    echo "  - Section Title: " . ($has_section_title ? "OK" : "N/A") . "\n";
    echo "  - Gold Underline: " . ($has_gold_underline ? "OK" : "N/A") . "\n";
}
curl_close($ch);
