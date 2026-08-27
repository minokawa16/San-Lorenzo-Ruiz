<?php
/**
 * Test script for Parishioner Desktop Enhancement & Mobile Preservation.
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

// 1. Header checks
$headerContent = file_get_contents($root . '/templates/header.php');
check(strpos($headerContent, 'user-global-actions') !== false, 'Header contains user-global-actions');
check(strpos($headerContent, 'parish-profile-pill-btn') !== false, 'Header contains parish-profile-pill-btn');
check(strpos($headerContent, 'userProfileDropdown') !== false, 'Header contains userProfileDropdown ID');
check(strpos($headerContent, 'dropdown-menu-end') !== false, 'Dropdown is positioned at the right (dropdown-menu-end)');

// 2. Sidebar checks
$sidebarContent = file_get_contents($root . '/includes/user-sidebar.php');
check(strpos($sidebarContent, 'San Lorenzo Ruiz') !== false, 'Sidebar contains church title');
check(strpos($sidebarContent, 'Main Menu') !== false || strpos($sidebarContent, 'nav.main_menu') !== false, 'Sidebar contains Main Menu section');
check(strpos($sidebarContent, 'Communication') !== false || strpos($sidebarContent, 'nav.communication') !== false, 'Sidebar contains Communication section');
check(strpos($sidebarContent, 'Account') !== false || strpos($sidebarContent, 'nav.account') !== false, 'Sidebar contains Account section');
check(strpos($sidebarContent, 'fa-table-cells-large') !== false, 'Sidebar uses fa-table-cells-large icon');

// 3. Calendar checks
$scheduleContent = file_get_contents($root . '/users/view-schedule.php');
check(strpos($scheduleContent, 'full-width-calendar') !== false, 'Calendar has full-width container');
check(strpos($scheduleContent, 'calendar-toolbar-card') !== false, 'Calendar has top toolbar filter card');
check(strpos($scheduleContent, 'calendar-legend-bar') !== false, 'Calendar has category legend bar');
check(strpos($scheduleContent, 'upcoming-events-grid') !== false, 'Calendar has upcoming public schedules grid');
check(strpos($scheduleContent, 'eventDetailsModal') !== false, 'Calendar has details modal');
check(strpos($scheduleContent, 'prev,next today') !== false, 'Calendar has prev, next, and today toolbar navigation');

// 4. My Requests checks
$myRequestsContent = file_get_contents($root . '/views/users/my-requests.php');
check(strpos($myRequestsContent, 'Reference #') !== false, 'My Requests has Reference # column');
check(strpos($myRequestsContent, 'Request Type') !== false, 'My Requests has Request Type column');
check(strpos($myRequestsContent, 'getStatusBadgeClass') !== false, 'My Requests has dynamic status badge classes');

// 5. CSS checks
$cssBundle = file_get_contents($root . '/assets/css/tugon-core.bundle.min.css');
check(strpos($cssBundle, '@media (min-width: 1024px)') !== false, 'CSS bundle includes min-width: 1024px desktop queries');
check(strpos($cssBundle, 'parish-profile-pill-btn') !== false, 'CSS bundle includes parish-profile-pill-btn styles');
check(strpos($cssBundle, '.calendar-panel.full-width-calendar') !== false, 'CSS bundle includes full-width calendar styles');

// 6. Compact Desktop AI Chatbot checks
check(strpos($cssBundle, 'width: 440px') !== false, 'CSS bundle includes compact desktop AI panel width (440px)');
check(strpos($cssBundle, 'height: 560px') !== false, 'CSS bundle includes compact desktop AI panel height (560px)');
check(strpos($cssBundle, 'bottom: 80px') !== false, 'CSS bundle includes compact desktop AI positioning (bottom: 80px)');
check(strpos($cssBundle, 'right: 24px') !== false, 'CSS bundle includes compact desktop AI positioning (right: 24px)');
// 7. Mobile Header Color checks
check(strpos($cssBundle, 'linear-gradient(135deg, #2E3A2D 0%, #263225 100%)') !== false, 'CSS bundle includes dark parish green for mobile header');

echo "=== PARISHIONER DESKTOP ENHANCEMENT VERIFICATION ===\n\n";
foreach ($successes as $s) {
    echo "$s\n";
}
if (!empty($errors)) {
    echo "\nERRORS:\n";
    foreach ($errors as $e) {
        echo "$e\n";
    }
    exit(1);
} else {
    echo "\nALL " . count($successes) . " VERIFICATION CHECKS PASSED!\n";
    exit(0);
}
