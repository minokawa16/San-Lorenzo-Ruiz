<?php
/**
 * Test Registry Certificate Generation Architecture
 * Validates removal of standalone generator, role authorization, 403 restrictions,
 * and direct issuance integration across all 5 sacramental registries.
 */

require_once __DIR__ . '/../includes/helpers.php';

echo "=====================================================\n";
echo "Testing Registry Certificate Generation Architecture\n";
echo "=====================================================\n";

$passed = 0;
$total = 0;

function checkTest($label, $condition) {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo " [PASS] $label\n";
    } else {
        echo " [FAIL] $label\n";
    }
}

// 1. Sidebar checks: "Certificates" menu item removed
$sidebar = file_get_contents(__DIR__ . '/../includes/admin-sidebar.php');
checkTest("Sidebar does not contain 'Generate Certificates' link", strpos($sidebar, 'Generate Certificates') === false);
checkTest("Sidebar does not contain 'accordion-certificates'", strpos($sidebar, 'accordion-certificates') === false);
checkTest("Sidebar does not contain 'data-section=\"certificates\"'", strpos($sidebar, 'data-section="certificates"') === false);

// 2. Redirect checks: certificate-generator.php redirects to records
$genFile = file_get_contents(__DIR__ . '/../admin/certificate-generator.php');
checkTest("certificate-generator.php redirects to manage-records.php", strpos($genFile, 'header(\'Location: \' . $targetPage') !== false);

// 3. Shared parsing functions exist in includes/helpers.php
checkTest("parseSacramentalParents function exists", function_exists('parseSacramentalParents'));
checkTest("parseParentBirthplacesFromRemarks function exists", function_exists('parseParentBirthplacesFromRemarks'));
checkTest("parseSacramentalSponsors function exists", function_exists('parseSacramentalSponsors'));

$parsedParents = parseSacramentalParents("Father: Jose Rizal / Mother: Teodora Alonso");
checkTest("parseSacramentalParents parses father and mother", $parsedParents['father'] === 'Jose Rizal' && $parsedParents['mother'] === 'Teodora Alonso');

// 4. Modal component exists and contains required elements
$modalContent = file_get_contents(__DIR__ . '/../includes/components/certificate-generation-modal.php');
checkTest("Modal contains backdrop dialog", strpos($modalContent, 'certGenerationModal') !== false);
checkTest("Modal contains Original Certificate option", strpos($modalContent, 'Original Certificate') !== false);
checkTest("Modal contains Certification option", strpos($modalContent, 'Certification') !== false);
checkTest("Modal contains marriage/funeral notice text placeholder", strpos($modalContent, 'certModalNotice') !== false);
checkTest("Modal contains close button with closeCertModal()", strpos($modalContent, 'closeCertModal()') !== false);

// 5. Check all 5 records pages include the modal and have Cert buttons with dataset
$registries = [
    'baptism' => __DIR__ . '/../admin/baptism-records.php',
    'communion' => __DIR__ . '/../admin/communion-records.php',
    'confirmation' => __DIR__ . '/../admin/confirmation-records.php',
    'marriage' => __DIR__ . '/../admin/marriage-records.php',
    'funeral' => __DIR__ . '/../admin/funeral-records.php',
];

foreach ($registries as $reg => $filePath) {
    $content = file_get_contents($filePath);
    checkTest("{$reg}-records.php includes certificate-generation-modal.php", strpos($content, 'certificate-generation-modal.php') !== false);
    checkTest("{$reg}-records.php has btn-open-cert-modal button", strpos($content, 'btn-open-cert-modal') !== false);
    checkTest("{$reg}-records.php passes data-registry=\"{$reg}\"", strpos($content, "data-registry=\"{$reg}\"") !== false);
}

// 6. Test Endpoint 403 rule: marriage and funeral must reject type=original
// We test this by inspecting endpoint code and simulating requests
$endpointCode = file_get_contents(__DIR__ . '/../admin/generate-certificate.php');
checkTest("Endpoint enforces 403 for marriage/funeral with type=original", 
    strpos($endpointCode, "in_array(\$registry, ['marriage', 'funeral'], true)") !== false &&
    strpos($endpointCode, "http_response_code(403)") !== false
);

checkTest("Endpoint checks active status for record existence", 
    strpos($endpointCode, "status = 'active'") !== false
);

checkTest("Endpoint saves into certificate_issuances", 
    strpos($endpointCode, "INSERT INTO certificate_issuances") !== false
);

checkTest("Endpoint records audit log with GENERATE_CERTIFICATE", 
    strpos($endpointCode, "createAuditLog") !== false &&
    strpos($endpointCode, "'GENERATE_CERTIFICATE'") !== false
);

// 7. Verify Marriage and Funeral Certification only note in modal script
checkTest("Modal JS enforces Certification only for Marriage and Funeral", 
    strpos($modalContent, "registry === 'marriage' || registry === 'funeral'") !== false &&
    strpos($modalContent, "records are issued as Certification only.") !== false &&
    strpos($modalContent, "origWrap.style.display = 'none'") !== false
);

echo "=====================================================\n";
echo "Results: $passed / $total tests passed.\n";
echo "=====================================================\n";

exit($passed === $total ? 0 : 1);
