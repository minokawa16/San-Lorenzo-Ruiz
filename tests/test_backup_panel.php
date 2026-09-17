<?php
/**
 * Automated Verification for Backup Parish Records Panel
 */

$standalone_file = __DIR__ . '/../backup-panel.html';
$settings_file = __DIR__ . '/../admin/settings.php';

$errors = [];
$checks = 0;

function assertCondition($cond, $msg) {
    global $errors, $checks;
    $checks++;
    if (!$cond) {
        $errors[] = "FAILED: " . $msg;
        echo "[FAIL] " . $msg . "\n";
    } else {
        echo "[PASS] " . $msg . "\n";
    }
}

echo "=== Testing backup-panel.html ===\n";
assertCondition(file_exists($standalone_file), "backup-panel.html exists");

$html = file_get_contents($standalone_file);

assertCondition(strpos($html, 'Backup Parish Records') !== false, "Contains header title 'Backup Parish Records'");
assertCondition(strpos($html, "Export a copy of your parish's records for safekeeping.") !== false, "Contains correct subtitle");
assertCondition(strpos($html, 'Last backup') !== false, "Contains 'Last backup' note");
assertCondition(strpos($html, 'Select record types to include') !== false, "Contains 'Select record types to include' label");
assertCondition(strpos($html, 'Select all') !== false, "Contains 'Select all' button");
assertCondition(strpos($html, 'Deselect all') !== false, "Contains 'Deselect all' button");

// Check exactly 3 record-type rows
preg_match_all('/class="record-row(?:\s+[^"]*)?"/', $html, $matches);
assertCondition(count($matches[0]) === 3, "Contains exactly 3 record-type rows (found: " . count($matches[0]) . ")");

assertCondition(strpos($html, 'Sacramental Records') !== false, "Row 1: Sacramental Records exists");
assertCondition(strpos($html, 'All Baptism, Confirmation, Marriage, and First Communion registers') !== false, "Row 1: Sacramental Records description matches");
assertCondition(strpos($html, 'Parishioners') !== false, "Row 2: Parishioners exists");
assertCondition(strpos($html, 'All registered parishioner profiles, contact info, and status') !== false, "Row 2: Parishioners description matches");
assertCondition(strpos($html, 'Requests') !== false, "Row 3: Requests exists");
assertCondition(strpos($html, 'All certificate, blessing, and sacramental service requests') !== false, "Row 3: Requests description matches");

// Check pre-selection
preg_match_all('/class="record-row\s+is-selected"/', $html, $selectedMatches);
assertCondition(count($selectedMatches[0]) === 3, "All 3 rows start pre-selected");

// Info note
assertCondition(strpos($html, 'Records are prepared as standard spreadsheet files (CSV), compatible with Microsoft Excel and LibreOffice, and packaged into a single ZIP file.') !== false, "Contains CSV info note");

// Footer live summary
assertCondition(strpos($html, 'record types selected') !== false, "Contains live summary count text");
assertCondition(strpos($html, '12.4 MB') !== false, "Initial calculated est. weight is 12.4 MB");

// Privacy note
assertCondition(strpos($html, 'Passwords &amp; credentials excluded') !== false || strpos($html, 'Passwords & credentials excluded') !== false, "Contains privacy note 'Passwords & credentials excluded'");

// Download button
assertCondition(strpos($html, 'Download Backup') !== false, "Contains 'Download Backup' button");

// Design system & fonts
assertCondition(strpos($html, '#F7F3EA') !== false, "Contains warm cream background color (#F7F3EA)");
assertCondition(strpos($html, '#1E3626') !== false, "Contains deep forest green accent (#1E3626)");
assertCondition(strpos($html, '#A6791E') !== false, "Contains muted antique gold accent (#A6791E)");
assertCondition(strpos($html, 'Lora') !== false, "Includes Lora serif font");
assertCondition(strpos($html, 'Work Sans') !== false, "Includes Work Sans sans-serif font");
assertCondition(strpos($html, 'prefers-color-scheme: dark') !== false, "Supports dark mode");

echo "\n=== Testing admin/settings.php ===\n";
assertCondition(file_exists($settings_file), "admin/settings.php exists");
$settings_content = file_get_contents($settings_file);

assertCondition(strpos($settings_content, 'Backup Parish Records') !== false, "admin/settings.php contains 'Backup Parish Records'");
assertCondition(strpos($settings_content, 'cat_sacramental') !== false, "admin/settings.php contains sacramental category");
assertCondition(strpos($settings_content, 'cat_parishioners') !== false, "admin/settings.php contains parishioners category");
assertCondition(strpos($settings_content, 'cat_requests') !== false, "admin/settings.php contains requests category");
assertCondition(strpos($settings_content, 'exportParishRecords') !== false, "admin/settings.php contains exportParishRecords handler");
assertCondition(strpos($settings_content, 'btn-download-backup') !== false, "admin/settings.php contains download button");

echo "\n===================================\n";
echo "Total checks: $checks, Errors: " . count($errors) . "\n";
if (count($errors) > 0) {
    echo "Verification failed!\n";
    exit(1);
} else {
    echo "All tests passed successfully!\n";
    exit(0);
}
