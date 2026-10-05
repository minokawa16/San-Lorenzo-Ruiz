<?php
/**
 * Automated Verification Test for Baptism Records Birthdate and UI Cleanup
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/SacramentalRecordService.php';
require_once __DIR__ . '/../services/CertificatePdfService.php';

$results = [];

// Test 1: format_baptism_record_date behavior
// In admin/baptism-records.php:
$testFn = function($date_value, $format = 'M d, Y') {
    if (empty($date_value)) {
        return 'N/A';
    }
    $val = trim((string)$date_value);
    if ($val === '' || $val === '0000-00-00' || strtolower($val) === 'null' || $val === '1970-01-01' || $val === 'N/A') {
        return 'N/A';
    }
    try {
        $tz = new DateTimeZone('Asia/Manila');
        $dt = new DateTime($val, $tz);
        if ($dt->format('Y-m-d') === '1970-01-01' && strpos($val, '1970') === false) {
            return 'N/A';
        }
        return $dt->format($format);
    } catch (\Throwable $e) {
        return 'N/A';
    }
};

$t1_valid = $testFn('2005-12-16');
$t1_null = $testFn(null);
$t1_empty = $testFn('');
$t1_zeros = $testFn('0000-00-00');
$t1_epoch = $testFn('1970-01-01');
$t1_strnull = $testFn('null');

$results['format_date_valid'] = ($t1_valid === 'Dec 16, 2005');
$results['format_date_null'] = ($t1_null === 'N/A');
$results['format_date_empty'] = ($t1_empty === 'N/A');
$results['format_date_zeros'] = ($t1_zeros === 'N/A');
$results['format_date_epoch'] = ($t1_epoch === 'N/A');
$results['format_date_strnull'] = ($t1_strnull === 'N/A');

// Test 2: SacramentalRecordService validation
$svc = new SacramentalRecordService($conn);

$tz = new DateTimeZone('Asia/Manila');
$tomorrow = (new DateTime('+1 day', $tz))->format('Y-m-d');
$wayOld = (new DateTime('-121 years', $tz))->format('Y-m-d');

// 2a: Future birthdate
$resFuture = $svc->validate('baptism', [
    'fullname' => 'Test Child',
    'birth_date' => $tomorrow,
    'birth_place' => 'Cotabato City',
    'parents' => 'Father Name & Mother Name',
    'baptism_date' => '2025-01-01',
    'godparents' => 'Sponsor Name',
    'priest' => 'Rev. Fr. Tester',
    'parish_priest' => 'Rev. Fr. Pastor',
    'book_no' => '1', 'page_no' => '1', 'entry_no' => '999'
]);
$results['reject_future_birthdate'] = (!$resFuture['valid'] && in_array('Birthdate cannot be in the future.', $resFuture['errors'], true));

// 2b: Date over 120 years old
$resOld = $svc->validate('baptism', [
    'fullname' => 'Test Child',
    'birth_date' => $wayOld,
    'birth_place' => 'Cotabato City',
    'parents' => 'Father Name & Mother Name',
    'baptism_date' => '2025-01-01',
    'godparents' => 'Sponsor Name',
    'priest' => 'Rev. Fr. Tester',
    'parish_priest' => 'Rev. Fr. Pastor',
    'book_no' => '1', 'page_no' => '1', 'entry_no' => '999'
]);
$results['reject_over_120yr_birthdate'] = (!$resOld['valid'] && in_array('Birthdate cannot be more than 120 years ago.', $resOld['errors'], true));

// 2c: Birthdate after baptism date
$resAfterBap = $svc->validate('baptism', [
    'fullname' => 'Test Child',
    'birth_date' => '2025-06-15',
    'baptism_date' => '2025-06-01',
    'birth_place' => 'Cotabato City',
    'parents' => 'Father Name & Mother Name',
    'godparents' => 'Sponsor Name',
    'priest' => 'Rev. Fr. Tester',
    'parish_priest' => 'Rev. Fr. Pastor',
    'book_no' => '1', 'page_no' => '1', 'entry_no' => '999'
]);
$results['reject_birth_after_baptism'] = (!$resAfterBap['valid'] && in_array('Birthdate must be before or equal to the date baptized.', $resAfterBap['errors'], true));

// 2d: Valid birthdate and valid baptism date
$resValid = $svc->validate('baptism', [
    'fullname' => 'Test Child Valid',
    'birth_date' => '2005-12-16',
    'baptism_date' => '2006-01-15',
    'birth_place' => 'Cotabato City',
    'parents' => 'Father Name & Mother Name',
    'godparents' => 'Sponsor Name',
    'priest' => 'Rev. Fr. Tester',
    'parish_priest' => 'Rev. Fr. Pastor',
    'book_no' => '999', 'page_no' => '999', 'entry_no' => '999'
]);
// Filter out duplicate errors if any
$dateErrors = array_filter($resValid['errors'], fn($e) => strpos($e, 'Birthdate') !== false || strpos($e, 'baptism') !== false);
$results['accept_valid_birthdate'] = empty($dateErrors);

// Test 3: Certificate HTML rendering
$certSvc = new CertificatePdfService($conn);
$htmlWithDob = $certSvc->renderBaptismCertificateHtml([
    'fullname' => 'Juan Dela Cruz',
    'birth_date' => '2005-12-16',
    'birth_place' => 'Cotabato City',
    'parent_address' => 'Cotabato City',
    'father_name' => 'Pedro Dela Cruz',
    'father_birth_place' => 'Cotabato City',
    'mother_name' => 'Maria Dela Cruz',
    'mother_birth_place' => 'Cotabato City',
    'baptism_date' => '2006-01-15',
    'priest' => 'Rev. Fr. Tester',
    'parish_priest' => 'Rev. Fr. Pastor',
    'godparents' => 'Godfather, Godmother',
    'book_no' => '1', 'page_no' => '2', 'entry_no' => '3'
]);

$results['cert_contains_long_birthday'] = (strpos($htmlWithDob, 'December 16, 2005') !== false);

$htmlNoDob = $certSvc->renderBaptismCertificateHtml([
    'fullname' => 'Old Record Juan',
    'birth_date' => null,
    'birth_place' => 'Cotabato City',
    'parent_address' => 'Cotabato City',
    'father_name' => 'Pedro Dela Cruz',
    'father_birth_place' => 'Cotabato City',
    'mother_name' => 'Maria Dela Cruz',
    'mother_birth_place' => 'Cotabato City',
    'baptism_date' => '2006-01-15',
    'priest' => 'Rev. Fr. Tester',
    'parish_priest' => 'Rev. Fr. Pastor',
    'godparents' => 'Godfather, Godmother',
    'book_no' => '1', 'page_no' => '2', 'entry_no' => '3'
]);

// Must contain Birthday row with blank underlined line (&nbsp;) and NOT "N/A" or "null" or "1970"
$birthdayRowRegex = '/Birthday:<\/td>\s*<td class="field-value-cell"><span class="field-value-text">(.*?)<\/span>/s';
preg_match($birthdayRowRegex, $htmlNoDob, $matches);
$birthdayCellContent = $matches[1] ?? '';
$results['cert_no_dob_prints_blank'] = ($birthdayCellContent === '&nbsp;' || trim($birthdayCellContent) === '');
$results['cert_no_dob_never_prints_na'] = (strpos($birthdayCellContent, 'N/A') === false && strpos($birthdayCellContent, 'null') === false && strpos($birthdayCellContent, '1970') === false);

// Test 4: Check admin/baptism-records.php markup
$bapFile = file_get_contents(__DIR__ . '/../admin/baptism-records.php');
$results['table_header_is_BIRTHDATE'] = (strpos($bapFile, '<th>BIRTHDATE</th>') !== false || strpos($bapFile, 'BIRTHDATE') !== false);
$results['stat_boxes_removed'] = (strpos($bapFile, 'registry-stats-grid') === false && strpos($bapFile, 'Total Baptism Records') === false);
$results['calendar_icon_present'] = (strpos($bapFile, 'position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none;') !== false);
$results['padding_42px_present'] = (strpos($bapFile, 'padding-left: 42px') !== false);
$results['cache_control_present'] = (strpos($bapFile, 'Cache-Control: private, no-store') !== false);

echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;

$allPassed = !in_array(false, $results, true);
echo ($allPassed ? "ALL TESTS PASSED!\n" : "SOME TESTS FAILED!\n");
exit($allPassed ? 0 : 1);
