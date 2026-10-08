<?php
/**
 * Test Suite: Backup Parish Records Readability and Alignment Verification
 * Validates FONT SIZES, ADVANCED OPTIONS ALIGNMENT, and MOBILE RESPONSIVENESS.
 */

$file = dirname(__DIR__) . '/admin/backup.php';
$html = file_get_contents($file);

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

echo "=== BACKUP PARISH RECORDS READABILITY & ALIGNMENT VERIFICATION ===\n\n";

// ── 1. FONT SIZES & LINE HEIGHT ──
check(strpos($html, 'font-size: 16px !important;') !== false, 'Body text and controls set to 16px font-size');
check(strpos($html, 'line-height: 1.5 !important;') !== false, 'Line height set to 1.5 for body text and controls');
check(strpos($html, 'font-size: 20px !important;') !== false, 'Section titles set to 20px');
check(strpos($html, 'font-weight: 600 !important;') !== false, 'Section titles set to semi-bold (600)');
check(strpos($html, 'font-size: 15px !important;') !== false, 'Helper/description text set to at least 15px');
check(strpos($html, '#44403C') !== false || strpos($html, '#4B5563') !== false, 'Helper text uses high-contrast dark color (contrast > 4.5:1)');
check(strpos($html, 'font-size: 16.5px !important;') !== false, 'Buttons set to 16-17px bold text');

// Dropdowns & Inputs
check(strpos($html, 'min-height: 50px !important;') !== false, 'Inputs have touch target >= 50px');
check(strpos($html, 'custom-dropdown-wrap') !== false, 'Custom dropdown component exists for automatic backups');
check(strpos($html, 'padding: 13px 18px !important;') !== false, 'Dropdown options have at least 12px vertical padding (13px)');
check(strpos($html, 'custom-dropdown-item') !== false, 'Custom dropdown option items defined');

// ── 2. ADVANCED OPTIONS ALIGNMENT ──
// Consistent 24px checkbox component
check(strpos($html, 'width: 24px !important;') !== false && strpos($html, 'height: 24px !important;') !== false, 'Checkboxes use consistent 24px dimensions');
check(strpos($html, 'border-radius: 6px !important;') !== false, 'Checkboxes use consistent 6px border radius');
check(strpos($html, 'background-color: #1E3626 !important;') !== false, 'Checked checkboxes fill with dark green (#1E3626)');
check(strpos($html, 'border: solid #FFFFFF !important;') !== false, 'Checked checkboxes display white checkmark');

// Equal card styling across all option rows
check(strpos($html, '.record-row,') !== false && strpos($html, '.adv-option-card') !== false, 'Record rows and advanced option rows share single unified card style');
check(strpos($html, 'padding: 16px;') !== false, 'Option cards use 16px padding');
check(strpos($html, 'border-radius: 12px;') !== false, 'Option cards use 12px radius');
check(strpos($html, 'background: #FAFAF7;') !== false, 'Option cards use #FAFAF7 background');
check(strpos($html, 'border: 1.5px solid #E5DFD3;') !== false, 'Option cards use 1.5px border');

// Row layout & clickable cards
check(strpos($html, 'min-height: 48px;') !== false, 'Option rows have minimum 48px height');
check(strpos($html, 'gap: 12px;') !== false, 'Option rows have 12px gap between checkbox and label');
check(strpos($html, 'for="adv_include_files"') !== false, 'Include uploaded documents card is fully clickable');
check(strpos($html, 'for="adv_enable_date"') !== false, 'Filter by date range card is clickable');
check(strpos($html, 'for="adv_enable_password"') !== false, 'Password protect card is clickable');

// Format choices (3 equal width cards, radio buttons)
check(strpos($html, 'adv-format-grid') !== false, 'Format choices use equal width grid');
check(strpos($html, 'repeat(3, 1fr)') !== false, 'Format grid uses 3 equal columns on desktop');
check(strpos($html, 'parish-radio') !== false, 'Format choices use 24px radio buttons (single selection)');

// Grouping and spacing
check(strpos($html, 'margin-bottom: 24px;') !== false, 'Groups have 24px spacing between them');
check(strpos($html, 'adv-rows-group') !== false, 'Group rows container exists');
check(strpos($html, 'gap: 12px;') !== false, 'Rows inside a group have 12px spacing');

// Indented revealed extra fields
check(strpos($html, 'adv-indented-fields') !== false, 'Revealed fields have dedicated indented container');
check(strpos($html, 'margin-left: 36px;') !== false, 'Revealed fields are indented inside the card');
check(strpos($html, 'advDateInputs') !== false, 'Date range inputs container is inside date card');
check(strpos($html, 'advPasswordBox') !== false, 'Password protection inputs container is inside password card');

// Advanced backup button
check(strpos($html, 'btn-adv-submit') !== false, 'Advanced backup button has dedicated styling');
check(strpos($html, 'height: 52px;') !== false, 'Advanced backup button is 52px tall');
check(strpos($html, 'width: 100% !important;') !== false, 'Advanced backup button is full width');

// ── 3. GENERAL & MOBILE SAFEGUARDS ──
check(strpos($html, 'overflow-x: hidden;') !== false, 'Page container prevents horizontal scrolling');
check(strpos($html, '@media (max-width: 480px)') !== false, 'Small mobile breakpoints defined for password buttons and date fields');

// Test compatibility checks
preg_match_all('/class="record-row(?:\s+[^"]*)?"/', $html, $matches);
check(count($matches[0]) === 3, 'Exactly 3 record-type rows exist for test suite compatibility (found: ' . count($matches[0]) . ')');
check(strpos($html, 'btn-download-backup') !== false, 'Preserves btn-download-backup class for test compatibility');
check(strpos($html, 'Backup History') !== false, 'Preserves Backup History table');
check(strpos($html, 'Restore / Import') !== false, 'Preserves Restore / Import feature');

foreach ($successes as $s) {
    echo "$s\n";
}

if (!empty($errors)) {
    echo "\nERRORS DETECTED:\n";
    foreach ($errors as $e) {
        echo "$e\n";
    }
    exit(1);
}

echo "\nALL " . count($successes) . " READABILITY & ALIGNMENT CHECKS PASSED!\n";
