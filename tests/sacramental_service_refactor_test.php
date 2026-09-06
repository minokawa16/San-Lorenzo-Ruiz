<?php
/**
 * Test Suite: Sacramental Service Forms Refactor Verification
 * Validates:
 * 1. Date synchronization and removal of redundant "Preferred Date" in Section 3
 * 2. Pre-Baptismal Investigation Sheet 4-part subcard layout
 * 3. Pre-Nuptial / Marriage Investigation Sheet 4-part subcard layout
 * 4. PHP backend date binding and canonical description formatting
 */

$test_file = __DIR__ . '/../users/request-service.php';
$css_file = __DIR__ . '/../assets/css/request-modern.css';

echo "=== Sacramental Service Refactor Verification ===\n";

// 1. Check PHP syntax
$output = [];
$return_var = 0;
exec('php -l ' . escapeshellarg($test_file), $output, $return_var);
if ($return_var !== 0) {
    echo "FAIL: PHP syntax check failed: " . implode("\n", $output) . "\n";
    exit(1);
}
echo "PASS: PHP syntax is valid.\n";

$content = file_get_contents($test_file);
$css_content = file_get_contents($css_file);

// 2. Verify redundant "Preferred Date" date-picker is removed from Section 3
if (strpos($content, '<input type="date" class="form-control request-form-control" id="preferred_date"') !== false) {
    echo "FAIL: Section 3 still contains a date-picker input with id='preferred_date'.\n";
    exit(1);
}
echo "PASS: Redundant date-picker id='preferred_date' is removed from Section 3.\n";

// 3. Verify preferred_date is a hidden input
if (strpos($content, '<input type="hidden" id="preferred_date" name="preferred_date">') === false) {
    echo "FAIL: Hidden input id='preferred_date' missing.\n";
    exit(1);
}
echo "PASS: Hidden input id='preferred_date' is present for binding.\n";

// 4. Verify schedule sync badge is present
if (strpos($content, 'id="scheduleSyncCard"') === false || strpos($content, 'schedule-sync-card') === false) {
    echo "FAIL: scheduleSyncCard missing from Section 3.\n";
    exit(1);
}
echo "PASS: Schedule sync badge is present in Section 3.\n";

// 5. Verify Pre-Baptismal Investigation Sheet subcards
$baptism_checks = [
    'investigation-sheet-card baptism-sheet-card',
    '1. Child\'s Information',
    '2. Parents\' Information',
    '3. Sponsors (Godparents / Ninong &amp; Ninang)',
    '4. Proposed Baptism Schedule',
    'name="baptism_sheet[child_name]"',
    'name="baptism_sheet[birth_date]"',
    'name="baptism_sheet[birth_place]"',
    'name="baptism_sheet[father_name]"',
    'name="baptism_sheet[father_origin]"',
    'name="baptism_sheet[mother_name]"',
    'name="baptism_sheet[mother_origin]"',
    'name="baptism_sheet[parents_marriage]"',
    'name="baptism_sheet[sponsor_male_name]"',
    'name="baptism_sheet[sponsor_female_name]"',
    'name="baptism_sheet[baptism_date]"'
];

foreach ($baptism_checks as $check) {
    if (strpos($content, $check) === false) {
        echo "FAIL: Pre-Baptismal sheet missing check: {$check}\n";
        exit(1);
    }
}
echo "PASS: Pre-Baptismal Investigation Sheet contains all 4 subcards and canonical inputs.\n";

// 6. Verify Pre-Nuptial Investigation Sheet subcards
$marriage_checks = [
    'investigation-sheet-card marriage-sheet-card',
    '1. Groom (Nobyo) Information',
    '2. Bride (Nobya) Information',
    '3. Principal Witnesses / Sponsors (Ninong &amp; Ninang)',
    '4. Wedding Ceremony Schedule',
    'name="marriage_sheet[groom_name]"',
    'name="marriage_sheet[groom_birth_date]"',
    'name="marriage_sheet[groom_birth_place]"',
    'name="marriage_sheet[groom_residence]"',
    'name="marriage_sheet[groom_religion]"',
    'name="marriage_sheet[groom_father_name]"',
    'name="marriage_sheet[groom_mother_name]"',
    'name="marriage_sheet[bride_name]"',
    'name="marriage_sheet[bride_birth_date]"',
    'name="marriage_sheet[bride_birth_place]"',
    'name="marriage_sheet[bride_residence]"',
    'name="marriage_sheet[bride_religion]"',
    'name="marriage_sheet[bride_father_name]"',
    'name="marriage_sheet[bride_mother_name]"',
    'name="marriage_sheet[witness_male]"',
    'name="marriage_sheet[witness_female]"',
    'name="marriage_sheet[additional_sponsors]"',
    'name="marriage_sheet[wedding_date]"'
];

foreach ($marriage_checks as $check) {
    if (strpos($content, $check) === false) {
        echo "FAIL: Pre-Nuptial sheet missing check: {$check}\n";
        exit(1);
    }
}
echo "PASS: Pre-Nuptial Investigation Sheet contains all 4 subcards and canonical inputs.\n";

// 7. Verify review panel sections
$review_checks = [
    'id="reviewChildSection"',
    'id="reviewParentsSection"',
    'id="reviewGodparentsSection"',
    'id="reviewGroomSection"',
    'id="reviewBrideSection"',
    'id="reviewMarriageSponsorsSection"',
    'id="reviewScheduleInfo"'
];

foreach ($review_checks as $check) {
    if (strpos($content, $check) === false) {
        echo "FAIL: Review panel missing check: {$check}\n";
        exit(1);
    }
}
echo "PASS: Review panel contains all child, parent, sponsor, groom, bride, and schedule review sections.\n";

// 8. Verify backend date binding logic
$backend_checks = [
    '$preferred_date = $baptism_sheet[\'baptism_date\']',
    '$preferred_date = $marriage_sheet[\'wedding_date\']',
    'Preferred date: \' . $preferred_date',
    '$status = \'pending\';'
];

foreach ($backend_checks as $check) {
    if (strpos($content, $check) === false) {
        echo "FAIL: Backend date binding logic missing check: {$check}\n";
        exit(1);
    }
}
echo "PASS: Backend binds investigation dates as single source of truth and sets status to pending.\n";

// 9. Verify CSS styles for investigation cards and schedule-sync card
$css_checks = [
    '.investigation-sheet-card',
    '.investigation-subcard',
    '.investigation-subcard-title',
    '.investigation-grid-2',
    '.investigation-grid-full',
    '.schedule-sync-card'
];

foreach ($css_checks as $check) {
    if (strpos($css_content, $check) === false) {
        echo "FAIL: CSS missing rule: {$check}\n";
        exit(1);
    }
}
echo "PASS: CSS contains all investigation layout and schedule-sync rules.\n";

echo "\nALL SACRAMENTAL SERVICE TESTS PASSED SUCCESSFULLY!\n";
