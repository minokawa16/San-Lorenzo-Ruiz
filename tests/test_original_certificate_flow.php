<?php
/**
 * Test Suite for Original Certificate Flow:
 * - Omission of Purpose field (Step 3: Certificate Details)
 * - Sequential step renumbering (no gaps)
 * - Omission of optional "Attach Supporting Document" block
 * - Preservation of Certification (Registry Extract) flow exactly as-is
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
assertTest(strpos($content, "supportingDocSection.style.display = 'none'") !== false, 'JS hides supportingDocSection for Original Certificate');
assertTest(strpos($content, "(!isOriginal && (isBap || isConf)) ? 'block' : 'none'") !== false, 'JS retains supportingDocSection for Certification flow only');

// 4. Client-side Form Validation
assertTest(strpos($content, 'if (!isOriginalCert) {') !== false, 'JS skips Purpose validation on submit when isOriginalCert is true');

// 5. Server-side PHP Processing & Validation
assertTest(strpos($content, '$is_original_certificate = ($selected_category === \'certificate\') || (($certificate_meta[$request_type][\'category\'] ?? \'\') === \'certificate\');') !== false, 'PHP accurately identifies Original Certificate request');
assertTest(strpos($content, '!$is_original_certificate && !array_key_exists($purpose, $certificate_purposes)') !== false, 'PHP skips Purpose array validation for Original Certificate');
assertTest(strpos($content, '!$is_original_certificate && $purpose === \'others\'') !== false, 'PHP skips Purpose other validation for Original Certificate');
assertTest(strpos($content, '!$is_original_certificate && ($is_baptism || $is_confirmation) && $has_supporting_doc') !== false, 'PHP saves supporting doc only for Certification flow');

// 6. Required Documents Step Intact
assertTest(strpos($content, 'name="requirement_files[]"') !== false, 'Required requirement_files[] input is retained');
assertTest(strpos($content, 'Upload all requirements') !== false, 'Required "Upload all requirements" label is retained');

// Output summary
echo "=== Original Certificate Flow Test Results ===\n";
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
    echo "\nAll " . count($passes) . " Original Certificate Flow tests passed successfully!\n";
}
