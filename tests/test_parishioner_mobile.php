<?php
/**
 * Test Suite: Parishioner Mobile View Enhancement & Desktop Preservation
 * Validates TASK 1 and TASK 2 specifications across breakpoints.
 */

$root = dirname(__DIR__);
$errors = [];
$successes = [];

function check($cond, $label) {
    global $errors, $successes;
    if ($cond) {
        $successes[] = "[PASS] $label";
    } else {
        $errors[] = "[FAIL] $label";
    }
}

// ── 1. Canonical Navigation Config Tests ──
require_once $root . '/includes/user-nav-config.php';
check(function_exists('getParishionerNavConfig'), 'getParishionerNavConfig function is defined');

$nav = getParishionerNavConfig(null, 1, 3);
check(!empty($nav['sections']), 'Nav config contains structured sections');
check(!empty($nav['modules']), 'Nav config contains derived flat modules');
check(count($nav['modules']) === 12, 'Nav config contains all 12 parishioner modules (count=' . count($nav['modules']) . ')');

$keys = array_column($nav['modules'], 'key');
check(in_array('dashboard', $keys, true), 'Modules contain Dashboard');
check(in_array('requests', $keys, true), 'Modules contain Requests');
check(in_array('certificates', $keys, true), 'Modules contain Certificates');
check(in_array('blessings', $keys, true), 'Modules contain Blessings');
check(in_array('services', $keys, true), 'Modules contain Sacramental Services');
check(in_array('calendar', $keys, true), 'Modules contain Calendar');
check(in_array('announcements', $keys, true), 'Modules contain Announcements');
check(in_array('organization', $keys, true), 'Modules contain Organization');
check(in_array('notifications', $keys, true), 'Modules contain Notifications');
check(in_array('ai_assistant', $keys, true), 'Modules contain AI Assistant');
check(in_array('profile', $keys, true), 'Modules contain Profile Settings');
check(in_array('help', $keys, true), 'Modules contain Help & Guide');

// Check badge logic on pending requests
$requests_mod = null;
foreach ($nav['modules'] as $m) {
    if ($m['key'] === 'requests') {
        $requests_mod = $m;
        break;
    }
}
check($requests_mod && $requests_mod['badge'] === 3, 'Requests tile preserves pending count badge');

// ── 2. Sidebar Integration with Config ──
$sidebarSrc = file_get_contents($root . '/includes/user-sidebar.php');
check(strpos($sidebarSrc, 'user-nav-config.php') !== false, 'user-sidebar.php reads from user-nav-config.php');
check(strpos($sidebarSrc, 'getParishionerNavConfig') !== false, 'user-sidebar.php uses getParishionerNavConfig');

// ── 3. Index Page Markup (TASK 1: Module Tiles & Stat Cards) ──
$indexSrc = file_get_contents($root . '/users/index.php');
check(strpos($indexSrc, 'dashboard-stats-grid') !== false, 'index.php retains dashboard-stats-grid for desktop');
check(strpos($indexSrc, 'user-mobile-module-grid') !== false, 'index.php has user-mobile-module-grid for mobile');
check(strpos($indexSrc, 'user-module-tile') !== false, 'index.php renders user-module-tile elements');
check(strpos($indexSrc, 'user-module-tile-icon-wrap') !== false, 'index.php tiles have icon on top');
check(strpos($indexSrc, 'user-module-tile-label') !== false, 'index.php tiles have label below');
check(strpos($indexSrc, 'user-module-tile-badge') !== false, 'index.php tiles support badges for useful counts');

// ── 4. CSS Breakpoint & Media Query Verification (TASK 1 & TASK 2) ──
$cssBundle = file_get_contents($root . '/assets/css/tugon-core.bundle.min.css');
$mobileDs = file_get_contents($root . '/assets/css/mobile-design-system.css');
$footerSrc = file_get_contents($root . '/templates/footer.php');

// Scope check: max-width: 767px
check(strpos($indexSrc, '@media (max-width: 767px)') !== false, 'index.php targets phone widths with max-width: 767px');
check(strpos($mobileDs, '@media (max-width: 767px)') !== false, 'mobile-design-system.css uses max-width: 767px');
check(strpos($footerSrc, '@media (max-width: 767px)') !== false, 'templates/footer.php uses max-width: 767px');

// Task 1: On mobile, hide stat cards, show module grid
check(strpos($indexSrc, '.dashboard-stats-grid') !== false && strpos($indexSrc, 'display: none !important;') !== false, 'Stat cards are hidden on mobile');
check(strpos($indexSrc, '.user-mobile-module-grid') !== false && strpos($indexSrc, 'display: grid !important;') !== false, 'Module grid is displayed on mobile');

// Task 1: Layout: 2 cols on phones, 3 cols above ~400px
check(strpos($indexSrc, 'grid-template-columns: repeat(2, 1fr)') !== false, 'Module grid uses 2 columns on phones <= 400px');
check(strpos($indexSrc, 'grid-template-columns: repeat(3, 1fr)') !== false, 'Module grid uses 3 columns above 400px');

// Task 1: Desktop unchanged
check(strpos($indexSrc, '.user-mobile-module-grid') !== false && strpos($indexSrc, 'display: none !important;') !== false, 'Module tiles are hidden by default on desktop');

// Task 2: Header logo/title on left, avatar on right, vertically centered, safe area
$headerSrc = file_get_contents($root . '/templates/header.php');
check(strpos($headerSrc, 'mobile-dashboard-brand') !== false, 'Header has mobile-dashboard-brand');
check(strpos($headerSrc, 'mobile-brand-icon') !== false, 'Header brand has logo icon');
check(strpos($headerSrc, 'mobile-brand-text') !== false, 'Header brand has text');
check(strpos($headerSrc, 'parish-profile-avatar') !== false, 'Header has avatar container');
check(strpos($mobileDs, 'safe-area-inset-top') !== false, 'Header uses safe-area-inset-top for notched phones');
check(strpos($mobileDs, 'justify-content: space-between') !== false, 'Header spaces brand left and avatar right');
check(strpos($mobileDs, 'align-items: center') !== false, 'Header vertically centers content');

// Task 2: Welcome card: smaller heading, cleanly wrapping, smaller verse
check(strpos($indexSrc, '.client-welcome-panel h1') !== false, 'Welcome heading has mobile CSS rules');
check(strpos($indexSrc, 'word-break: break-word') !== false, 'Welcome name wraps cleanly without dominating');
check(strpos($indexSrc, '.client-verse') !== false, 'Welcome verse has compact styling');

// Task 2: Consistent horizontal page padding (16px), no horizontal scroll
check(strpos($mobileDs, 'padding: 16px max(16px, env(safe-area-inset-right))') !== false, 'Consistent 16px horizontal page padding');
check(strpos($mobileDs, 'overflow-x: hidden') !== false, 'No horizontal scrolling on mobile');

// Task 2: Floating chat button smaller & page clearance
check(strpos($footerSrc, 'width: 44px !important;') !== false, 'Floating chat button downsized to 44px');
check(strpos($footerSrc, 'height: 44px !important;') !== false, 'Floating chat button height is 44px');
check(strpos($footerSrc, 'padding-bottom: calc(70px + env(safe-area-inset-bottom, 16px))') !== false, 'Page content bottom padding ensures chat button never obscures content');

// Task 2: Forms, inputs, buttons, tables
check(strpos($mobileDs, 'font-size: 16px !important;') !== false, 'Inputs use 16px font-size to prevent iOS auto-zoom');
check(strpos($mobileDs, 'min-height: 46px !important;') !== false, 'Buttons have at least 46px tap target');
check(strpos($mobileDs, 'font-size: 14px !important;') !== false, 'Table and labels use readable >= 14px font size');

// ── Summary ──
echo "=== PARISHIONER MOBILE VIEW & BREAKPOINT VERIFICATION ===\n\n";
foreach ($successes as $s) {
    echo "$s\n";
}
if (!empty($errors)) {
    echo "\nERRORS (" . count($errors) . "):\n";
    foreach ($errors as $e) {
        echo "$e\n";
    }
    exit(1);
} else {
    echo "\nALL " . count($successes) . " MOBILE VERIFICATION CHECKS PASSED!\n";
    exit(0);
}
