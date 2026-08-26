<?php
$cookie = __DIR__ . '/railway_auth_cookie.txt';
$base_url = 'https://tugon-web-production.up.railway.app';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_SSL_VERIFYPEER => false
]);

$pages = [
    'Dashboard' => '/admin/dashboard.php',
    'Schedule Calendar' => '/admin/manage-calendar.php',
    'Certificate Generator' => '/admin/certificate-generator.php'
];

echo "=== Specific Validation for Calendar, Certificates & Search Icon ===\n";
foreach ($pages as $label => $uri) {
    curl_setopt($ch, CURLOPT_URL, $base_url . $uri);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $has_search_icon_in_topbar = (bool)preg_match('/parish-nav-search-form[^>]*>[^<]*<i class="fas/i', $html) || (bool)preg_match('/dashboard-header-search-form[^>]*>[^<]*<i class="fas/i', $html);
    $has_top_nav = strpos($html, 'parish-top-nav-bar') !== false;
    $has_cert_cards = strpos($html, 'pds-cert-card') !== false;
    $has_calendar_grid = strpos($html, 'calendar-grid') !== false;

    echo "[$label] (HTTP $code)\n";
    echo "  - Top Nav Bar Present: " . ($has_top_nav ? "YES" : "NO") . "\n";
    echo "  - Magnifying Glass Icon Removed from Search Pill: " . (!$has_search_icon_in_topbar ? "YES (CLEAN)" : "NO (STILL PRESENT)") . "\n";
    if ($label === 'Certificate Generator') {
        echo "  - Modern Dashboard Card Grid: " . ($has_cert_cards ? "YES" : "NO") . "\n";
    }
    if ($label === 'Schedule Calendar') {
        echo "  - Clean Calendar Grid: " . ($has_calendar_grid ? "YES" : "NO") . "\n";
    }
}
curl_close($ch);
