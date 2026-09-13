<?php
/**
 * Test Certificate Generation Flow
 * Tests simulating certificate generation requests for all sacramental types.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=====================================================\n";
echo "Testing Certificate Generation Backend Logic & Queries\n";
echo "=====================================================\n";

$tests_passed = 0;
$tests_total = 0;

function assertCondition($name, $condition) {
    global $tests_passed, $tests_total;
    $tests_total++;
    if ($condition) {
        $tests_passed++;
        echo " [PASS] $name\n";
    } else {
        echo " [FAIL] $name\n";
    }
}

// 1. Test Confirmation Records Query
$stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ? AND (status='active' OR status IS NULL OR status='')");
$id = 2;
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$conf_record = $res->fetch_assoc();
$stmt->close();
assertCondition("Confirmation record #2 query succeeded", !empty($conf_record));
if ($conf_record) {
    assertCondition("Confirmation fullname exists", !empty($conf_record['fullname']));
}

// 2. Test Communion Records Query
$stmt = $conn->prepare("SELECT * FROM first_communion_records WHERE communion_id = ? AND (status='active' OR status IS NULL OR status='')");
$id = 2;
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$comm_record = $res->fetch_assoc();
$stmt->close();
assertCondition("Communion record #2 query succeeded", !empty($comm_record));

// 3. Test Baptism Records Query
$stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ?");
$id = 1;
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$bap_record = $res->fetch_assoc();
$stmt->close();
assertCondition("Baptism record #1 query succeeded", !empty($bap_record));

// 4. Test Funeral Records Query
$stmt = $conn->prepare("SELECT * FROM funeral_records WHERE funeral_id = ? AND (status='active' OR status IS NULL OR status='')");
$id = 1;
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$fun_record = $res->fetch_assoc();
$stmt->close();
assertCondition("Funeral record #1 query succeeded", !empty($fun_record));

// 5. Test Confirmation Override Logic Simulation
$post_data = [
    'conf_override_fullname' => 'GERALD M. CATULONG',
    'override_confirmation_name' => 'GERALD M. CATULONG',
    'override_confirmation_date' => '2026-11-28',
    'conf_override_parents' => 'JAY-R P. CATULONG',
    'override_sponsor' => 'NENITA M. CATULONG',
    'conf_override_priest' => 'REV. FR. ALBERTO G. CAHILIG, OMI',
];

$record = $conf_record;
$ov_fullname     = trim($post_data['conf_override_fullname'] ?? ($post_data['override_fullname'] ?? ''));
$ov_conf_name    = trim($post_data['override_confirmation_name'] ?? '');
$ov_conf_date    = trim($post_data['override_confirmation_date'] ?? '');
$ov_parents      = trim($post_data['conf_override_parents'] ?? ($post_data['override_parents'] ?? ''));
$ov_sponsor      = trim($post_data['override_sponsor'] ?? '');
$ov_priest       = trim($post_data['conf_override_priest'] ?? ($post_data['override_priest'] ?? ''));
if ($ov_fullname)  $record['fullname']           = $ov_fullname;
if ($ov_conf_name) $record['confirmation_name']  = $ov_conf_name;
if ($ov_conf_date) $record['confirmation_date']  = $ov_conf_date;
if ($ov_parents)   $record['parents']            = $ov_parents;
if ($ov_sponsor)   $record['sponsor']            = $ov_sponsor;
if ($ov_priest)    $record['bishop_priest'] = $record['parish_priest'] = $ov_priest;

$missing = [];
if (empty($record['fullname']))           $missing[] = 'Full Name';
if (empty($record['confirmation_date']))  $missing[] = 'Date of Confirmation';
if (empty($record['bishop_priest']) && empty($record['parish_priest'])) $missing[] = 'Officiating Priest';

assertCondition("Simulated confirmation overrides applied correctly", $record['fullname'] === 'GERALD M. CATULONG');
assertCondition("Simulated confirmation priest override applied correctly", $record['bishop_priest'] === 'REV. FR. ALBERTO G. CAHILIG, OMI');
assertCondition("Simulated confirmation validation passes without missing fields", empty($missing));

// 6. Test Communion Override Logic Simulation
$comm_post_data = [
    'com_override_fullname' => 'MARIA SANTOS',
    'override_communion_date' => '2026-05-15',
    'override_domicile' => 'BARANGAY 1, COTABATO CITY',
    'com_override_parents' => 'JUAN SANTOS AND ANA SANTOS',
    'com_override_priest' => 'REV. FR. ALBERTO G. CAHILIG, OMI',
    'override_catechist_coordinator' => 'SIS. LOURDES',
    'override_principal' => 'DR. SANTOS'
];

$rec_c = $comm_record;
$ov_fullname      = trim($comm_post_data['com_override_fullname'] ?? ($comm_post_data['override_fullname'] ?? ''));
$ov_comm_date     = trim($comm_post_data['override_communion_date'] ?? '');
$ov_domicile      = trim($comm_post_data['override_domicile'] ?? '');
$ov_parents       = trim($comm_post_data['com_override_parents'] ?? ($comm_post_data['override_parents'] ?? ''));
$ov_priest        = trim($comm_post_data['com_override_priest'] ?? ($comm_post_data['override_priest'] ?? ''));
$ov_catechist     = trim($comm_post_data['override_catechist_coordinator'] ?? '');
$ov_principal     = trim($comm_post_data['override_principal'] ?? '');
if ($ov_fullname)  $rec_c['fullname']              = $ov_fullname;
if ($ov_comm_date) $rec_c['communion_date']        = $ov_comm_date;
if ($ov_domicile)  $rec_c['domicile']              = $ov_domicile;
if ($ov_parents)   $rec_c['parents']               = $ov_parents;
if ($ov_priest)    $rec_c['priest'] = $rec_c['parish_priest'] = $ov_priest;
if ($ov_catechist) $rec_c['catechist_coordinator'] = $ov_catechist;
if ($ov_principal) $rec_c['principal']             = $ov_principal;

$missing_c = [];
if (empty($rec_c['fullname']))       $missing_c[] = "Recipient's Full Name";
if (empty($rec_c['communion_date'])) $missing_c[] = 'Date of First Communion';

assertCondition("Simulated communion overrides applied correctly", $rec_c['fullname'] === 'MARIA SANTOS');
assertCondition("Simulated communion validation passes without missing fields", empty($missing_c));

echo "=====================================================\n";
echo "Results: $tests_passed / $tests_total tests passed.\n";
echo "=====================================================\n";
