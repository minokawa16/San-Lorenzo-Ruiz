<?php
/**
 * Live Verification Suite for Standardized Headers on Railway
 */
$cookie = __DIR__ . '/railway_auth_cookie.txt';
if (file_exists($cookie)) unlink($cookie);

$base_url = 'https://tugon-web-production.up.railway.app';

// 1. GET login page for CSRF
$ch = curl_init("$base_url/auth/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
]);
$login_html = curl_exec($ch);

preg_match('/name=["\']_csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $login_html, $m);
$csrf = $m[1] ?? '';
echo "CSRF Token extracted: " . substr($csrf, 0, 16) . "...\n";

// 2. POST login credentials
curl_setopt_array($ch, [
    CURLOPT_URL => "$base_url/auth/login.php",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf_token' => $csrf,
        'identifier' => 'tugonparish@gmail.com',
        'password' => 'Parishioner@123',
        'form_action' => 'login'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
$post_res = curl_exec($ch);
$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
echo "Effective URL after login: $effective_url\n";

// 3. Test All Key Admin Pages
$pages = [
    'Dashboard' => '/admin/dashboard.php',
    'Manage Parishioners' => '/admin/manage-parishioners.php',
    'Manage Requests' => '/admin/manage-requests.php',
    'Sacramental Records' => '/admin/manage-records.php',
    'Baptism Records' => '/admin/baptism-records.php',
    'Confirmation Records' => '/admin/confirmation-records.php',
    'Communion Records' => '/admin/communion-records.php',
    'Marriage Records' => '/admin/marriage-records.php',
    'Funeral Records' => '/admin/funeral-records.php',
    'Announcements' => '/admin/manage-announcements.php',
    'Reservations' => '/admin/manage-reservations.php',
    'Certificate Generator' => '/admin/certificate-generator.php',
    'Settings & Maintenance' => '/admin/settings.php',
    'Registration Verification' => '/admin/verify-registrations.php',
    'Archives' => '/admin/archives.php',
    'Audit Logs' => '/admin/audit-logs.php',
    'AI Assistant' => '/admin/ai-assistant.php',
    'Integration Health' => '/admin/integration-health.php'
];

echo "\n=======================================================\n";
echo " LIVE HEADER & SUB-HEADER STANDARDIZATION RESULTS\n";
echo "=======================================================\n";

$all_passed = true;
foreach ($pages as $label => $uri) {
    curl_setopt($ch, CURLOPT_URL, $base_url . $uri);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $has_top_nav = strpos($html, 'parish-top-nav-bar') !== false;
    $has_badge_icon = strpos($html, 'parish-nav-badge-icon') !== false;
    $has_profile_pill = strpos($html, 'parish-profile-pill-btn') !== false;
    $has_back_link = strpos($html, 'parish-back-link') !== false;
    $has_section_title = strpos($html, 'parish-section-title') !== false;
    $has_gold_underline = strpos($html, 'parish-gold-underline') !== false;

    echo "[$label] (HTTP $code)\n";
    echo "  - Top Nav Bar:             " . ($has_top_nav ? "PASSED" : "FAILED") . "\n";
    echo "  - Nav Badge Icon (Square): " . ($has_badge_icon ? "PASSED" : "FAILED") . "\n";
    echo "  - Profile Pill Widget:     " . ($has_profile_pill ? "PASSED" : "FAILED") . "\n";
    if ($uri !== '/admin/dashboard.php') {
        echo "  - Back Button (< Go Back): " . ($has_back_link ? "PASSED" : "FAILED") . "\n";
        echo "  - Section Title (Serif):   " . ($has_section_title ? "PASSED" : "FAILED") . "\n";
        echo "  - Gold Accent Underline:   " . ($has_gold_underline ? "PASSED" : "FAILED") . "\n";
    }

    if (!$has_top_nav || !$has_badge_icon || !$has_profile_pill) {
        $all_passed = false;
    }
    if ($uri !== '/admin/dashboard.php' && (!$has_back_link || !$has_section_title || !$has_gold_underline)) {
        $all_passed = false;
    }
}
curl_close($ch);

echo "\n=======================================================\n";
echo " Overall Status: " . ($all_passed ? "ALL PAGES PERFECTLY STANDARDIZED!" : "SOME CHECKS FAILED") . "\n";
echo "=======================================================\n";
