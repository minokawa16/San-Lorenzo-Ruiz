<?php
/**
 * Test Suite for Two-Step Certificate Selection Flow
 */

$root = dirname(__DIR__);
$filePath = $root . '/users/request-certificate.php';
$content = file_get_contents($filePath);

$errors = [];
$passes = [];

function assertTest($condition, $name) {
    global $errors, $passes;
    if ($condition) {
        $passes[] = "[PASS] " . $name;
    } else {
        $errors[] = "[FAIL] " . $name;
    }
}

// 1. Check Category View Elements
assertTest(strpos($content, 'id="categorySelectionView"') !== false, 'Category selection view container (#categorySelectionView) exists');
assertTest(strpos($content, 'What do you need?') !== false, 'Header question "What do you need?" is present');
assertTest(strpos($content, 'data-category="certification"') !== false, 'Certification category card exists');
assertTest(strpos($content, 'data-category="certificate"') !== false, 'Original Certificate category card exists');
assertTest(strpos($content, '5 types available') !== false, 'Certification card has "5 types available" badge');
assertTest(strpos($content, '3 types available') !== false, 'Original Certificate card has "3 types available" badge');
assertTest(strpos($content, 'Registry Extract') !== false, 'Registry Extract badge is present');
assertTest(strpos($content, 'Canonical Certificate') !== false, 'Canonical Certificate badge is present');

// 2. Check "Change selection" controls
assertTest(substr_count($content, 'data-action="change-category"') >= 2, 'Change selection buttons present for both filtered views');
assertTest(strpos($content, 'Change selection') !== false, '"Change selection" label is present');

// 3. Check CSS rules
assertTest(strpos($content, '.category-choice-grid') !== false, 'CSS .category-choice-grid exists');
assertTest(strpos($content, '.category-choice-card') !== false, 'CSS .category-choice-card exists');
assertTest(strpos($content, '.category-choice-card.is-selected') !== false, 'CSS selected indicator for category cards exists');
assertTest(strpos($content, '.btn-change-category') !== false, 'CSS .btn-change-category exists');
assertTest(strpos($content, '.category-step-fade') !== false, 'CSS .category-step-fade animation exists');

// 4. Check JavaScript state management
assertTest(strpos($content, 'function showCategoryFlow') !== false, 'JS function showCategoryFlow exists');
assertTest(strpos($content, 'isSwitchingCategory') !== false, 'JS category switching detection exists');
assertTest(strpos($content, 'toggleBaptismFields') !== false, 'JS baptism fields toggle exists and integrated');
assertTest(strpos($content, 'changeCategoryBtns') !== false, 'JS change category button listeners exist');

// Output summary
echo "=== Two-Step Certificate Selection Flow Test Results ===\n";
foreach ($passes as $p) {
    echo $p . "\n";
}
if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo $e . "\n";
    }
    exit(1);
} else {
    echo "\nAll " . count($passes) . " Two-Step Certificate Selection tests passed successfully!\n";
}
