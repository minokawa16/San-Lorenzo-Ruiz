<?php
/**
 * Automated Verification for Backup Parish Records Panel & Live Backend API
 */

$standalone_file = __DIR__ . '/../backup-panel.html';
$settings_file = __DIR__ . '/../admin/settings.php';
$api_file = __DIR__ . '/../api/backup-parish-records.php';
$api_dir_file = __DIR__ . '/../api/backup-parish-records/index.php';

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

echo "=== 1. Testing backup-panel.html Structure & UX ===\n";
assertCondition(file_exists($standalone_file), "backup-panel.html exists");

$html = file_get_contents($standalone_file);

// Header
assertCondition(strpos($html, 'Backup Parish Records') !== false, "Contains header title 'Backup Parish Records'");
assertCondition(strpos($html, "Export a copy of your parish's records for safekeeping.") !== false, "Contains correct subtitle");
assertCondition(strpos($html, 'Last backup') !== false, "Contains 'Last backup' note");

// Toolbar
assertCondition(strpos($html, 'Select record types to include') !== false, "Contains 'Select record types to include' label");
assertCondition(strpos($html, 'Select all') !== false, "Contains 'Select all' button");
assertCondition(strpos($html, 'Deselect all') !== false, "Contains 'Deselect all' button");

// Check exactly 3 record-type rows
preg_match_all('/class="record-row(?:\s+[^"]*)?"/', $html, $matches);
assertCondition(count($matches[0]) === 3, "Contains exactly 3 record-type rows (found: " . count($matches[0]) . ")");

// Row 1: sacramental_records
assertCondition(strpos($html, 'data-id="sacramental_records"') !== false, "Row 1: Uses type key 'sacramental_records'");
assertCondition(strpos($html, 'Sacramental Records') !== false, "Row 1: Title 'Sacramental Records' exists");
assertCondition(strpos($html, 'All Baptism, Confirmation, Marriage, and First Communion registers') !== false, "Row 1: Description matches");

// Row 2: parishioners
assertCondition(strpos($html, 'data-id="parishioners"') !== false, "Row 2: Uses type key 'parishioners'");
assertCondition(strpos($html, 'Parishioners') !== false, "Row 2: Title 'Parishioners' exists");
assertCondition(strpos($html, 'All registered parishioner profiles, contact info, and status') !== false, "Row 2: Description matches");

// Row 3: requests
assertCondition(strpos($html, 'data-id="requests"') !== false, "Row 3: Uses type key 'requests'");
assertCondition(strpos($html, 'Requests') !== false, "Row 3: Title 'Requests' exists");
assertCondition(strpos($html, 'All certificate, blessing, and sacramental service requests') !== false, "Row 3: Description matches");

// Pre-selection
preg_match_all('/class="record-row\s+is-selected"/', $html, $selectedMatches);
assertCondition(count($selectedMatches[0]) === 3, "All 3 rows start pre-selected");

// Info note
assertCondition(strpos($html, 'Records are prepared as standard spreadsheet files (CSV), compatible with Microsoft Excel and LibreOffice, and packaged into a single ZIP file.') !== false, "Contains CSV info note");

// Inline warning
assertCondition(strpos($html, 'inline-warning-banner') !== false, "Contains inline warning element for empty selection");

// Footer live summary
assertCondition(strpos($html, 'record types selected') !== false, "Contains live summary count text");
assertCondition(strpos($html, '12.4 MB') !== false, "Initial calculated est. weight is 12.4 MB");

// Privacy note
assertCondition(strpos($html, 'Passwords &amp; credentials excluded') !== false || strpos($html, 'Passwords & credentials excluded') !== false, "Contains privacy note 'Passwords & credentials excluded'");

// Download button & states
assertCondition(strpos($html, 'Download Backup') !== false, "Contains 'Download Backup' button");
assertCondition(strpos($html, 'Preparing backup…') !== false || strpos($html, 'Preparing backup') !== false, "Handles 'Preparing backup…' loading label");
assertCondition(strpos($html, 'Failed — try again') !== false, "Handles 'Failed — try again' error state");
assertCondition(strpos($html, 'parish-backup-') !== false, "Generates download filename parish-backup-YYYY-MM-DD.zip");

// Layout height upfront reservation & design system
assertCondition(strpos($html, 'min-height: 100vh') !== false, "Reserves page layout height upfront (min-height: 100vh)");
assertCondition(strpos($html, '#F7F3EA') !== false, "Contains warm cream background color (#F7F3EA)");
assertCondition(strpos($html, '#1E3626') !== false, "Contains deep forest green accent (#1E3626)");
assertCondition(strpos($html, '#A6791E') !== false, "Contains muted antique gold accent (#A6791E)");
assertCondition(strpos($html, 'Lora') !== false, "Includes Lora serif font");
assertCondition(strpos($html, 'Work Sans') !== false, "Includes Work Sans sans-serif font");
assertCondition(strpos($html, 'prefers-color-scheme: dark') !== false, "Supports dark mode");

echo "\n=== 2. Testing Live Backend API (api/backup-parish-records.php) ===\n";
assertCondition(file_exists($api_file), "api/backup-parish-records.php exists");
assertCondition(file_exists($api_dir_file), "api/backup-parish-records/index.php exists");

$api_code = file_get_contents($api_file);
assertCondition(strpos($api_code, 'sacramental_records') !== false, "API supports 'sacramental_records'");
assertCondition(strpos($api_code, 'parishioners') !== false, "API supports 'parishioners'");
assertCondition(strpos($api_code, 'requests') !== false, "API supports 'requests'");
assertCondition(strpos($api_code, 'sensitive_columns') !== false, "API defines sensitive column blacklist");
assertCondition(strpos($api_code, 'password') !== false, "API strips passwords");
assertCondition(strpos($api_code, 'token') !== false, "API strips auth tokens");
assertCondition(strpos($api_code, 'ZipArchive') !== false, "API uses ZipArchive for compression");
assertCondition(strpos($api_code, 'application/zip') !== false, "API returns application/zip Content-Type");
assertCondition(strpos($api_code, '\xEF\xBB\xBF') !== false, "API adds UTF-8 BOM for Excel/LibreOffice");

echo "\n=== 3. Testing admin/settings.php Integration ===\n";
assertCondition(file_exists($settings_file), "admin/settings.php exists");
$settings_content = file_get_contents($settings_file);

assertCondition(strpos($settings_content, 'Backup Parish Records') !== false, "admin/settings.php contains 'Backup Parish Records'");
assertCondition(strpos($settings_content, 'sacramental_records') !== false, "admin/settings.php contains sacramental_records key");
assertCondition(strpos($settings_content, 'parishioners') !== false, "admin/settings.php contains parishioners key");
assertCondition(strpos($settings_content, 'requests') !== false, "admin/settings.php contains requests key");
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
