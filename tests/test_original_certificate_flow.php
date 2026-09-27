<?php
/**
 * Test Suite for Certificate Flow:
 * - Omission of Purpose field (Step 3: Certificate Details) for Original Certificate
 * - Sequential step renumbering (no gaps)
 * - Complete removal of optional "Attach Supporting Document" block
 * - Removal of 'Please present a valid ID when claiming.'
 * - Removal of description sentences below Certification and Original Certificate titles
 * - Server-side validation logic
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

// 1. Structure & HTML IDs
assertTest(strpos($content, 'id="stepPurposeSection"') !== false, 'Step Purpose section (#stepPurposeSection) ID exists');
assertTest(strpos($content, 'id="stepPurposeNumber"') !== false, 'Step Purpose number (#stepPurposeNumber) ID exists');
assertTest(strpos($content, 'id="stepUploadSection"') !== false, 'Step Upload section (#stepUploadSection) ID exists');
assertTest(strpos($content, 'id="stepUploadNumber"') !== false, 'Step Upload number (#stepUploadNumber) ID exists');
assertTest(strpos($content, 'id="paymentReleaseStep"') !== false, 'Payment Release step (#paymentReleaseStep) ID exists');
assertTest(strpos($content, 'id="stepPaymentNumber"') !== false, 'Step Payment number (#stepPaymentNumber) ID exists');
assertTest(strpos($content, 'id="certificateCategoryInput"') !== false, 'Hidden certificate_category input (#certificateCategoryInput) exists');

// 2. Conditional PHP rendering based on $is_original_post
assertTest(strpos($content, "id=\"stepPurposeSection\" <?php echo (\$is_original_post) ? 'style=\"display: none;\"' : ''; ?>") !== false, 'Purpose section is hidden when is_original_post is true');
assertTest(strpos($content, "id=\"stepUploadNumber\"><?php echo (\$is_original_post) ? '3' : '4'; ?>") !== false, 'Upload step is renumbered to 3 for original certificate in PHP');
assertTest(strpos($content, "id=\"stepPaymentNumber\"><?php echo (\$is_original_post) ? '4' : '5'; ?>") !== false, 'Payment step is renumbered to 4 for original certificate in PHP');
assertTest(strpos($content, "id=\"purpose\" name=\"purpose\" <?php echo (\$is_original_post) ? '' : 'required'; ?>") !== false, 'Purpose select required attribute omitted for original certificate in PHP');

// 3. JavaScript Dynamic Renumbering and Visibility
assertTest(strpos($content, 'function updateCategoryUI') !== false, 'JS updateCategoryUI function exists');
assertTest(strpos($content, "stepUploadNumber.textContent = isOriginal ? '3' : '4'") !== false, 'JS dynamically renumbers Upload step to 3 for Original Certificate');
assertTest(strpos($content, "stepPaymentNumber.textContent = isOriginal ? '4' : '5'") !== false, 'JS dynamically renumbers Payment step to 4 for Original Certificate');
assertTest(strpos($content, "stepPurposeSection.style.display = isOriginal ? 'none' : ''") !== false, 'JS hides Purpose section for Original Certificate');

// 4. Removal of Supporting Document block from HTML and JS
assertTest(strpos($content, 'id="supportingDocSection"') === false, 'supportingDocSection removed from HTML');
assertTest(strpos($content, 'Attach Supporting Document (Optional)') === false, '"Attach Supporting Document (Optional)" removed from form');

// 5. Removal of "Please present a valid ID when claiming."
assertTest(strpos($content, 'Please present a valid ID when claiming.') === false, '"Please present a valid ID when claiming." removed from release info');

// 6. Removal of sentences below Certification and Original Certificate
assertTest(strpos($content, "A certified extract transcribed directly from the parish's official canonical registry books.") === false, 'Certification description sentence removed');
assertTest(strpos($content, "An official canonical commemorative certificate for a sacrament celebrated in this parish.") === false, 'Original Certificate description sentence removed');

// 7. Client-side Form Validation
assertTest(strpos($content, 'if (!isOriginalCert) {') !== false, 'JS skips Purpose validation on submit when isOriginalCert is true');

// 8. Server-side PHP Processing & Validation
assertTest(strpos($content, '$is_original_certificate = ($selected_category === \'certificate\') || (($certificate_meta[$request_type][\'category\'] ?? \'\') === \'certificate\');') !== false, 'PHP accurately identifies Original Certificate request');
assertTest(strpos($content, '!$is_original_certificate && !array_key_exists($purpose, $certificate_purposes)') !== false, 'PHP skips Purpose array validation for Original Certificate');
assertTest(strpos($content, '!$is_original_certificate && $purpose === \'others\'') !== false, 'PHP skips Purpose other validation for Original Certificate');

// 9. Required Documents Step Intact
assertTest(strpos($content, 'name="requirement_files[]"') !== false, 'Required requirement_files[] input is retained');
assertTest(strpos($content, 'Upload all requirements') !== false, 'Required "Upload all requirements" label is retained');

// Output summary
echo "=== Certificate Flow Test Results ===\n";
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
    echo "\nAll " . count($passes) . " Certificate Flow tests passed successfully!\n";
}
