<?php
/**
 * Certificate Generator Module - Builds sacramental certificate data for preview, print, and verification.
 */
require_once __DIR__ . '/../includes/session.php';
include_once __DIR__ . '/../database/config.php';
include_once __DIR__ . '/../includes/helpers.php';

requireAdmin();
requirePermission('certificates.manage');

$error = '';
$success = '';

// Handle certificate generation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    $cert_type = $_POST['cert_type'] ?? '';
    $record_id = intval($_POST['record_id'] ?? 0);
    
    if ($cert_type == 'baptism' || $cert_type == 'baptism_certification') {
        $stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ?");
        $stmt->bind_param('i', $record_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res && $res->num_rows > 0) {
            $record = $res->fetch_assoc();
            $stmt->close();
            
            // Collect overrides or fallback to record values
            $fullname = trim($_POST['override_fullname'] ?? ($record['fullname'] ?? ''));
            $birth_place = trim($_POST['override_birth_place'] ?? ($record['birth_place'] ?? ''));
            $birth_date = trim($_POST['override_birth_date'] ?? ($record['birth_date'] ?? ''));
            $residence = trim($_POST['override_residence'] ?? ($record['parent_address'] ?? ($record['parish_address'] ?? '')));
            $father_name = trim($_POST['override_father_name'] ?? ($record['father_name'] ?? ''));
            $father_birth_place = trim($_POST['override_father_birth_place'] ?? ($record['father_birth_place'] ?? ''));
            $mother_name = trim($_POST['override_mother_name'] ?? ($record['mother_name'] ?? ''));
            $mother_birth_place = trim($_POST['override_mother_birth_place'] ?? ($record['mother_birth_place'] ?? ''));
            $baptism_date = trim($_POST['override_baptism_date'] ?? ($record['baptism_date'] ?? ''));
            $officiating_priest = trim($_POST['override_officiating_priest'] ?? ($_POST['override_priest'] ?? ''));
            $priest_in_charge = trim($_POST['override_priest_in_charge'] ?? ($_POST['override_parish_priest'] ?? ''));

            // Fallback parsing for parents if still blank
            if (empty($father_name) || empty($mother_name)) {
                $parents = trim((string)($record['parents'] ?? ''));
                if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $parents, $m)) {
                    if (empty($father_name)) $father_name = trim($m[1]);
                    if (empty($mother_name)) $mother_name = trim($m[2]);
                } else {
                    $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $parents);
                    $parts = array_values(array_filter(array_map('trim', $parts)));
                    if (count($parts) >= 2) {
                        if (empty($father_name)) $father_name = $parts[0];
                        if (empty($mother_name)) $mother_name = $parts[1];
                    } elseif (count($parts) === 1 && empty($father_name)) {
                        $father_name = $parts[0];
                    }
                }
            }

            // Fallback parsing for parent birthplaces from remarks if still blank
            if (empty($father_birth_place) && !empty($record['remarks'])) {
                if (preg_match('/father(?:\'s)?\s*birthplace\s*[:\-]\s*([^\|\n\r;]+)/i', $record['remarks'], $m)) {
                    $father_birth_place = trim($m[1]);
                }
            }
            if (empty($mother_birth_place) && !empty($record['remarks'])) {
                if (preg_match('/mother(?:\'s)?\s*birthplace\s*[:\-]\s*([^\|\n\r;]+)/i', $record['remarks'], $m)) {
                    $mother_birth_place = trim($m[1]);
                }
            }

            // Collect sponsors
            $raw_sponsors = $_POST['override_sponsors'] ?? [];
            if (!is_array($raw_sponsors)) {
                $raw_sponsors = preg_split('/[\r\n]+/', (string)$raw_sponsors);
            }
            $valid_sponsors = [];
            foreach ($raw_sponsors as $s) {
                $s = trim((string)$s);
                if ($s !== '') $valid_sponsors[] = $s;
            }
            if (empty($valid_sponsors) && !empty($record['godparents'])) {
                $parts = preg_split('/,\s*|\s+and\s+|\s*;\s*|\s*\/\s*|[\r\n]+/i', (string)$record['godparents']);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '' && !in_array($p, $valid_sponsors, true)) $valid_sponsors[] = $p;
                }
            }

            // Validation: Every required field must be complete before generating
            $missing = [];
            if ($fullname === '') $missing[] = 'Full Name';
            if ($birth_place === '') $missing[] = 'Birthplace';
            if ($birth_date === '') $missing[] = 'Birthday';
            if ($residence === '') $missing[] = 'Residence';
            if ($father_name === '') $missing[] = "Father's Name";
            if ($father_birth_place === '') $missing[] = "Father's Birthplace";
            if ($mother_name === '') $missing[] = "Mother's Name";
            if ($mother_birth_place === '') $missing[] = "Mother's Birthplace";
            if ($baptism_date === '') $missing[] = 'Date of Baptism';
            if ($officiating_priest === '') $missing[] = 'Officiating Priest';
            if ($priest_in_charge === '') $missing[] = 'Priest in Charge (Parish Priest)';
            if (count($valid_sponsors) < 2) $missing[] = 'At least 2 Sponsors (Ninong-Ninang)';

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please fill them in the form below.';
            } else {
                $record['fullname'] = $fullname;
                $record['birth_place'] = $birth_place;
                $record['birth_date'] = $birth_date;
                $record['residence'] = $residence;
                $record['parent_address'] = $residence;
                $record['father_name'] = $father_name;
                $record['father_birth_place'] = $father_birth_place;
                $record['mother_name'] = $mother_name;
                $record['mother_birth_place'] = $mother_birth_place;
                $record['baptism_date'] = $baptism_date;
                $record['priest'] = $officiating_priest;
                $record['officiating_priest'] = $officiating_priest;
                $record['parish_priest'] = $priest_in_charge;
                $record['priest_in_charge'] = $priest_in_charge;
                $record['sponsors'] = $valid_sponsors;
                $record['godparents'] = implode("\n", $valid_sponsors);

                // Persist any updated parent birthplaces or residence into baptism_records
                $up = $conn->prepare("UPDATE baptism_records SET father_name = IF(father_name IS NULL OR father_name = '', ?, father_name), father_birth_place = IF(father_birth_place IS NULL OR father_birth_place = '', ?, father_birth_place), mother_name = IF(mother_name IS NULL OR mother_name = '', ?, mother_name), mother_birth_place = IF(mother_birth_place IS NULL OR mother_birth_place = '', ?, mother_birth_place), parent_address = IF(parent_address IS NULL OR parent_address = '', ?, parent_address) WHERE baptism_id = ?");
                if ($up) {
                    $up->bind_param('sssssi', $father_name, $father_birth_place, $mother_name, $mother_birth_place, $residence, $record_id);
                    $up->execute();
                    $up->close();
                }

                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php?id=' . $record_id . '&type=' . urlencode($cert_type));
                exit;
            }
        } else {
            $stmt->close();
        }
    } elseif ($cert_type == 'communion' || $cert_type == 'first_communion_certification') {
        $stmt = $conn->prepare("SELECT * FROM first_communion_records WHERE communion_id = ? AND (status='active' OR status IS NULL OR status='')");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            // Apply overrides from form (supporting both scoped and generic names)
            $ov_fullname      = trim($_POST['com_override_fullname'] ?? ($_POST['override_fullname'] ?? ''));
            $ov_comm_date     = trim($_POST['override_communion_date'] ?? '');
            $ov_domicile      = trim($_POST['override_domicile'] ?? '');
            $ov_parents       = trim($_POST['com_override_parents'] ?? ($_POST['override_parents'] ?? ''));
            $ov_officiating   = trim($_POST['com_override_officiating_priest'] ?? ($_POST['com_override_priest'] ?? ($_POST['override_priest'] ?? '')));
            $ov_in_charge     = trim($_POST['com_override_priest_in_charge'] ?? ($_POST['com_override_parish_priest'] ?? ($_POST['override_priest_in_charge'] ?? '')));
            $ov_catechist     = trim($_POST['override_catechist_coordinator'] ?? '');
            $ov_principal     = trim($_POST['override_principal'] ?? '');
            if ($ov_fullname)  $record['fullname']              = $ov_fullname;
            if ($ov_comm_date) $record['communion_date']        = $ov_comm_date;
            if ($ov_domicile)  $record['domicile']              = $ov_domicile;
            if ($ov_parents)   $record['parents']               = $ov_parents;
            if ($ov_catechist) $record['catechist_coordinator'] = $ov_catechist;
            if ($ov_principal) $record['principal']             = $ov_principal;
            $missing = [];
            if (empty($record['fullname']))       $missing[] = "Recipient's Full Name";
            if (empty($record['communion_date']))  $missing[] = 'Date of First Communion';
            if ($ov_officiating === '')           $missing[] = 'Officiating Priest';
            if ($ov_in_charge === '')             $missing[] = 'Priest in Charge (Parish Priest)';
            
            $record['priest'] = $ov_officiating;
            $record['officiating_priest'] = $ov_officiating;
            $record['parish_priest'] = $ov_in_charge;
            $record['priest_in_charge'] = $ov_in_charge;

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please complete the form below.';
            } else {
                $signers = getFirstCommunionSigners($conn, $record);
                if (empty($record['catechist_coordinator'])) $record['catechist_coordinator'] = $signers['catechist_coordinator'];
                if (empty($record['principal']))              $record['principal']              = $signers['principal'];
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php?id=' . $record_id . '&type=' . urlencode($cert_type));
                exit;
            }
        } else { $error = 'Communion record not found or inactive.'; }
    } elseif ($cert_type == 'confirmation' || $cert_type == 'confirmation_certification') {
        $stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ? AND (status='active' OR status IS NULL OR status='')");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_fullname     = trim($_POST['conf_override_fullname'] ?? ($_POST['override_fullname'] ?? ''));
            $ov_conf_name    = trim($_POST['override_confirmation_name'] ?? '');
            $ov_conf_date    = trim($_POST['override_confirmation_date'] ?? '');
            $ov_parents      = trim($_POST['conf_override_parents'] ?? ($_POST['override_parents'] ?? ''));
            $ov_sponsor      = trim($_POST['override_sponsor'] ?? '');
            $ov_officiating  = trim($_POST['conf_override_officiating_priest'] ?? ($_POST['conf_override_priest'] ?? ($_POST['override_priest'] ?? '')));
            $ov_in_charge    = trim($_POST['conf_override_priest_in_charge'] ?? ($_POST['conf_override_parish_priest'] ?? ($_POST['override_priest_in_charge'] ?? '')));
            if ($ov_fullname)  $record['fullname']           = $ov_fullname;
            if ($ov_conf_name) $record['confirmation_name']  = $ov_conf_name;
            if ($ov_conf_date) $record['confirmation_date']  = $ov_conf_date;
            if ($ov_parents)   $record['parents']            = $ov_parents;
            if ($ov_sponsor)   $record['sponsor']            = $ov_sponsor;
            $missing = [];
            if (empty($record['fullname']))          $missing[] = 'Full Name';
            if (empty($record['confirmation_date'])) $missing[] = 'Date of Confirmation';
            if ($ov_officiating === '')              $missing[] = 'Officiating Priest';
            if ($ov_in_charge === '')                $missing[] = 'Priest in Charge (Parish Priest)';
            
            $record['bishop_priest'] = $ov_officiating;
            $record['officiating_priest'] = $ov_officiating;
            $record['parish_priest'] = $ov_in_charge;
            $record['priest_in_charge'] = $ov_in_charge;

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '.';
            } else {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php?id=' . $record_id . '&type=' . urlencode($cert_type));
                exit;
            }
        } else { $error = 'Confirmation record not found or inactive.'; }
    } elseif ($cert_type == 'marriage' || $cert_type == 'marriage_certification') {
        $stmt = $conn->prepare("SELECT * FROM marriage_records WHERE marriage_id = ? AND (status='active' OR status IS NULL OR status='')");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_husband      = trim($_POST['override_husband_name'] ?? '');
            $ov_wife         = trim($_POST['override_wife_name'] ?? '');
            $ov_wed_date     = trim($_POST['override_wedding_date'] ?? '');
            $ov_wed_loc      = trim($_POST['override_wedding_location'] ?? '');
            $ov_officiating  = trim($_POST['mar_override_officiating_priest'] ?? ($_POST['mar_override_priest'] ?? ($_POST['override_priest'] ?? '')));
            $ov_in_charge    = trim($_POST['mar_override_priest_in_charge'] ?? ($_POST['mar_override_parish_priest'] ?? ($_POST['override_priest_in_charge'] ?? '')));
            $ov_h_residence  = trim($_POST['override_husband_residence'] ?? '');
            $ov_w_residence  = trim($_POST['override_wife_residence'] ?? '');
            if ($ov_husband)     $record['husband_name']        = $ov_husband;
            if ($ov_wife)        $record['wife_name']           = $ov_wife;
            if ($ov_wed_date)    $record['wedding_date']        = $ov_wed_date;
            if ($ov_wed_loc)     $record['wedding_location']    = $ov_wed_loc;
            if ($ov_h_residence) $record['husband_residence']   = $ov_h_residence;
            if ($ov_w_residence) $record['wife_residence']      = $ov_w_residence;
            $missing = [];
            if (empty($record['husband_name'])) $missing[] = "Husband's Name";
            if (empty($record['wife_name']))    $missing[] = "Wife's Name";
            if (empty($record['wedding_date'])) $missing[] = 'Date of Wedding';
            if ($ov_officiating === '')         $missing[] = 'Officiating Priest';
            if ($ov_in_charge === '')           $missing[] = 'Priest in Charge (Parish Priest)';
            
            $record['officiating_priest'] = $ov_officiating;
            $record['priest'] = $ov_officiating;
            $record['parish_priest'] = $ov_in_charge;
            $record['priest_in_charge'] = $ov_in_charge;

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '.';
            } else {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php?id=' . $record_id . '&type=' . urlencode($cert_type));
                exit;
            }
        } else { $error = 'Marriage record not found or inactive.'; }
    } elseif ($cert_type == 'funeral_certification') {
        $stmt = $conn->prepare("SELECT * FROM funeral_records WHERE funeral_id = ? AND (status='active' OR status IS NULL OR status='')");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_deceased     = trim($_POST['override_deceased_name'] ?? '');
            $ov_burial_date  = trim($_POST['override_date_of_burial'] ?? '');
            $ov_burial_place = trim($_POST['override_place_of_burial'] ?? '');
            $ov_officiating  = trim($_POST['fun_override_officiating_priest'] ?? ($_POST['fun_override_priest'] ?? ($_POST['override_priest'] ?? '')));
            $ov_in_charge    = trim($_POST['fun_override_priest_in_charge'] ?? ($_POST['fun_override_parish_priest'] ?? ($_POST['override_priest_in_charge'] ?? '')));
            if ($ov_deceased)     $record['deceased_name']   = $ov_deceased;
            if ($ov_burial_date)  $record['date_of_burial']  = $ov_burial_date;
            if ($ov_burial_place) $record['place_of_burial'] = $ov_burial_place;
            $missing = [];
            if (empty($record['deceased_name']))  $missing[] = 'Deceased Name';
            if (empty($record['date_of_burial'])) $missing[] = 'Date of Burial';
            if ($ov_officiating === '')           $missing[] = 'Officiating Priest';
            if ($ov_in_charge === '')             $missing[] = 'Priest in Charge (Parish Priest)';
            
            $record['minister'] = $ov_officiating;
            $record['priest'] = $ov_officiating;
            $record['officiating_priest'] = $ov_officiating;
            $record['parish_priest'] = $ov_in_charge;
            $record['priest_in_charge'] = $ov_in_charge;

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '.';
            } else {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php?id=' . $record_id . '&type=' . urlencode($cert_type));
                exit;
            }
        } else { $error = 'Funeral record not found or inactive.'; }
    }
}

// Get available records
$baptism_count = $conn->query("SELECT COUNT(*) as count FROM baptism_records WHERE status='active'")->fetch_assoc()['count'];
$communion_count = $conn->query("SELECT COUNT(*) as count FROM first_communion_records WHERE status='active'")->fetch_assoc()['count'];
$confirmation_count = $conn->query("SELECT COUNT(*) as count FROM confirmation_records WHERE status='active'")->fetch_assoc()['count'];
$marriage_count = $conn->query("SELECT COUNT(*) as count FROM marriage_records WHERE status='active'")->fetch_assoc()['count'];
$funeral_count = $conn->query("SELECT COUNT(*) as count FROM funeral_records WHERE status='active'")->fetch_assoc()['count'];

$active_priests = getActivePriestsRoster($conn);
$default_parish_priest = 'Rev. Fr. Alberto G. Cahilig, O.M.I.';
foreach ($active_priests as $p) {
    if (!empty($p['is_default'])) {
        $default_parish_priest = $p['name'];
        break;
    }
}

$page_title = 'Certificate Generator';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Certificate Generator' => null
];

include __DIR__ . '/../templates/header.php';
?>

<style>
    /* Modal Viewport Capping — uses flex layout so the footer is always visible and clickable */
    #generateModal .modal-dialog {
        max-height: 90vh;
        margin: 1.5rem auto;
        display: flex;
        flex-direction: column;
    }
    #generateModal .modal-content {
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-radius: 12px;
    }
    #generateModal .modal-header {
        flex-shrink: 0;
        z-index: 1055;
    }
    /* Form uses flex so body scrolls and footer sticks — NO overflow:hidden on form */
    #generateModal #generatorRecordForm {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        margin: 0;
    }
    #generateModal .modal-body {
        overflow-y: auto;
        flex: 1 1 auto;
        padding: 1.25rem 1.5rem;
    }
    #generateModal .modal-footer {
        flex-shrink: 0;
        z-index: 1055;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
    }
    .priest-select-highlight {
        border: 2px solid #d97706 !important;
        background-color: #fffdf5 !important;
        font-weight: 600;
        color: #78350f !important;
        box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.14) !important;
    }

    /* Autocomplete / Typeahead priest suggestions styling */
    .priest-autocomplete-wrapper {
        position: relative;
    }
    .priest-autocomplete-dropdown {
        position: absolute;
        top: calc(100% + 2px);
        left: 0;
        right: 0;
        z-index: 1070;
        max-height: 220px;
        overflow-y: auto;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        background: #ffffff;
        padding: 4px 0;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        display: none;
    }
    .priest-autocomplete-dropdown.show {
        display: block;
    }
    .priest-autocomplete-item {
        padding: 7px 12px;
        font-size: 0.82rem;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: #1e293b;
        transition: background-color 0.12s ease;
    }
    .priest-autocomplete-item:hover,
    .priest-autocomplete-item.active {
        background-color: #f1f5f9;
        color: #0f172a;
    }
    .priest-autocomplete-item .priest-role-badge {
        font-size: 0.7rem;
        padding: 2px 6px;
        border-radius: 4px;
        background: #e2e8f0;
        color: #475569;
        white-space: nowrap;
        margin-left: 8px;
    }
    .priest-autocomplete-item.active .priest-role-badge {
        background: #cbd5e1;
    }
    .priest-autocomplete-item strong {
        color: #0284c7;
    }

    .pds-cert-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .pds-cert-card {
        background: #ffffff;
        border: 1px solid var(--border-warm, #d8d6cc);
        border-radius: 12px;
        padding: 22px 18px;
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }
    .pds-cert-card:hover {
        transform: translateY(-2px);
        border-color: #c4c1b5;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
    }
    .pds-cert-icon-wrap {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        margin-bottom: 12px;
    }
    .pds-cert-title {
        font-family: 'Playfair Display', Georgia, serif;
        font-size: 1.05rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 6px 0;
    }
    .pds-cert-count {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 14px;
    }
    .pds-cert-count strong {
        color: #1e293b;
    }
    .btn-primary-gold {
        background: #c89b3c !important;
        color: #1e293b !important;
        font-weight: 600 !important;
        border: none !important;
        border-radius: 6px !important;
        padding: 6px 16px !important;
        font-size: 0.82rem !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        transition: all 0.15s ease !important;
    }
    .btn-primary-gold:hover {
        background: #b58930 !important;
        color: #141d24 !important;
        transform: translateY(-1px) !important;
    }
    .pds-info-card {
        background: #ffffff;
        border: 1px solid var(--border-warm, #d8d6cc);
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }
</style>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <?php
        $page_header_title = 'Generate Certificates';
        $page_header_subtitle = 'Prepare, preview, and release official parish certificates from records or manual entry.';
        include __DIR__ . '/../includes/page_header.php';
        ?>
        <a href="manual-certificate-generator.php" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2">
            <i class="fas fa-file-signature"></i> Manual / Freeform Certificate
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-exclamation me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Sacramental Certificates Grid -->
    <h5 class="mb-3" style="font-family: 'Playfair Display', Georgia, serif; font-size: 1.15rem; font-weight: 700; color: #1e293b;">
        <i class="fas fa-scroll me-2 text-warning"></i> Official Sacramental Certificates
    </h5>
    <div class="pds-cert-grid">
        <!-- Baptism -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #e0f2fe; color: #0284c7;">
                <i class="fas fa-water"></i>
            </div>
            <h6 class="pds-cert-title">Baptism Certificates</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$baptism_count; ?></strong> Active Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="baptism">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>

        <!-- First Communion -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #fef3c7; color: #b45309;">
                <i class="fas fa-wheat-awn"></i>
            </div>
            <h6 class="pds-cert-title">First Communion Certificate</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$communion_count; ?></strong> Active Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="communion">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>

        <!-- Confirmation -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #e0e7ff; color: #4338ca;">
                <i class="fas fa-dove"></i>
            </div>
            <h6 class="pds-cert-title">Confirmation Certificates</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$confirmation_count; ?></strong> Active Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="confirmation">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>
    </div>

    <!-- Sacramental Certifications Grid -->
    <h5 class="mb-3 mt-4" style="font-family: 'Playfair Display', Georgia, serif; font-size: 1.15rem; font-weight: 700; color: #1e293b;">
        <i class="fas fa-file-signature me-2 text-warning"></i> Sacramental Certifications
    </h5>
    <div class="pds-cert-grid">
        <!-- Baptismal Certification -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #e0f2fe; color: #0284c7;">
                <i class="fas fa-file-signature"></i>
            </div>
            <h6 class="pds-cert-title">Baptismal Certification</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$baptism_count; ?></strong> Baptism Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="baptism_certification" data-record-type="baptism">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>

        <!-- Confirmation Certification -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #e0e7ff; color: #4338ca;">
                <i class="fas fa-file-circle-check"></i>
            </div>
            <h6 class="pds-cert-title">Confirmation Certification</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$confirmation_count; ?></strong> Confirmation Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="confirmation_certification" data-record-type="confirmation">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>

        <!-- First Communion Certification -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #fef3c7; color: #b45309;">
                <i class="fas fa-file-lines"></i>
            </div>
            <h6 class="pds-cert-title">First Communion Certification</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$communion_count; ?></strong> Communion Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="first_communion_certification" data-record-type="communion">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>

        <!-- Marriage Certification -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #fef2f2; color: #dc2626;">
                <i class="fas fa-ring"></i>
            </div>
            <h6 class="pds-cert-title">Marriage Certification</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$marriage_count; ?></strong> Marriage Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="marriage_certification" data-record-type="marriage">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>

        <!-- Funeral Certification -->
        <div class="pds-cert-card">
            <div class="pds-cert-icon-wrap" style="background: #f1f5f9; color: #475569;">
                <i class="fas fa-cross"></i>
            </div>
            <h6 class="pds-cert-title">Funeral Certification</h6>
            <div class="pds-cert-count"><strong><?php echo (int)$funeral_count; ?></strong> Funeral Records</div>
            <button class="btn-primary-gold" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="funeral_certification" data-record-type="funeral">
                <i class="fas fa-file-pdf"></i> Generate
            </button>
        </div>
    </div>
</div>

<!-- Datalist for priest suggestion fallback -->
<datalist id="priestSuggestionsDatalist">
    <?php foreach ($active_priests as $p): ?>
        <option value="<?php echo e($p['name']); ?>"><?php echo e($p['title']); ?></option>
    <?php endforeach; ?>
</datalist>

<!-- Generate Certificate Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="generateModalTitle"><i class="fas fa-file-pdf me-2 text-warning"></i> Generate Certificate</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="generatorRecordForm" novalidate>
                <?php echo csrfInput(); ?>
                <div class="modal-body p-4">
                    <input type="hidden" name="cert_type" id="cert_type">
                    <div class="mb-3">
                        <label for="record_id" class="form-label fw-bold text-dark">Select Record <span class="text-danger">*</span></label>
                        <select class="form-select" id="record_id" name="record_id" required>
                            <option value="">-- Loading records --</option>
                        </select>
                    </div>

                    <!-- Baptism Standard Record Fields & Overrides -->
                    <div id="baptismFieldsContainer" style="display: none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Standard parish baptism record format. Verify and complete all required fields below. All fields must be filled before generating the certificate.</div>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Full Name of Baptized <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_fullname" id="override_fullname">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Place of Birth <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_birth_place" id="override_birth_place">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_birth_date" id="override_birth_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Residence / Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_residence" id="override_residence">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_father_name" id="override_father_name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Father's Birthplace <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_father_birth_place" id="override_father_birth_place">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Mother's Name (including maiden name) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_mother_name" id="override_mother_name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Mother's Birthplace <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_mother_birth_place" id="override_mother_birth_place">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Date of Baptism <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_baptism_date" id="override_baptism_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">
                                    Priest in Charge (Parish Priest) <span class="text-danger">*</span>
                                </label>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input" name="override_priest_in_charge" id="override_priest_in_charge" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Parish presiding priest. Defaults to current parish priest; fully editable.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">
                                        Officiating Priest <span class="text-danger">*</span>
                                    </label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size: 0.68rem; letter-spacing: 0.02em;">
                                        <i class="fas fa-hand-pointer me-1"></i> Secretary Assigned
                                    </span>
                                </div>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input priest-select-highlight" name="override_officiating_priest" id="override_officiating_priest" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Assigned manually by secretary. Never carried over from parishioner requests.
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic Multi-Sponsors List -->
                        <div class="border rounded p-3 bg-light mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small fw-bold mb-0">
                                    Sponsors / Ninong-Ninang <span class="text-danger">*</span>
                                    <small class="text-muted fw-normal ms-1">(At least 2 required; each on its own line)</small>
                                </label>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="addModalSponsorBtn">
                                    <i class="fas fa-plus"></i> Add Sponsor
                                </button>
                            </div>
                            <div id="modalSponsorsList">
                                <!-- Dynamically populated sponsor inputs -->
                            </div>
                        </div>
                    </div>

                    <!-- First Communion Fields -->
                    <div id="communionFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>First Communion record. Verify and complete all required fields. <strong>Full Name</strong>, <strong>Date of First Communion</strong>, <strong>Priest in Charge</strong>, and <strong>Officiating Priest</strong> are required.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Full Name of Communicant <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="com_override_fullname" id="com_override_fullname">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of First Communion <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_communion_date" id="com_override_communion_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Domicile / Address</label>
                                <input type="text" class="form-control form-control-sm" name="override_domicile" id="com_override_domicile">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Parents</label>
                                <input type="text" class="form-control form-control-sm" name="com_override_parents" id="com_override_parents" placeholder="e.g. Juan Dela Cruz and Maria Santos">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Catechist Coordinator</label>
                                <input type="text" class="form-control form-control-sm" name="override_catechist_coordinator" id="com_override_catechist" placeholder="e.g. Sis. Lourdes Fernandez">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Principal</label>
                                <input type="text" class="form-control form-control-sm" name="override_principal" id="com_override_principal" placeholder="e.g. Principal Name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Priest in Charge (Parish Priest) <span class="text-danger">*</span></label>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input" name="com_override_priest_in_charge" id="com_override_priest_in_charge" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Parish presiding priest. Defaults to current parish priest; fully editable.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input priest-select-highlight" name="com_override_officiating_priest" id="com_override_officiating_priest" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Assigned manually by secretary. Never carried over from parishioner requests.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Confirmation Fields -->
                    <div id="confirmationFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Confirmation record. Verify all fields. <strong>Full Name</strong>, <strong>Date of Confirmation</strong>, <strong>Priest in Charge</strong>, and <strong>Officiating Bishop / Priest</strong> are required.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="conf_override_fullname" id="conf_override_fullname">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Confirmation Name</label>
                                <input type="text" class="form-control form-control-sm" name="override_confirmation_name" id="conf_override_confirmation_name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Confirmation <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_confirmation_date" id="conf_override_confirmation_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Sponsor</label>
                                <input type="text" class="form-control form-control-sm" name="override_sponsor" id="conf_override_sponsor" placeholder="Sponsor Name">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Parents</label>
                                <input type="text" class="form-control form-control-sm" name="conf_override_parents" id="conf_override_parents" placeholder="e.g. Juan Dela Cruz and Maria Santos">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Priest in Charge (Parish Priest) <span class="text-danger">*</span></label>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input" name="conf_override_priest_in_charge" id="conf_override_priest_in_charge" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Parish presiding priest. Defaults to current parish priest; fully editable.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Bishop / Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input priest-select-highlight" name="conf_override_officiating_priest" id="conf_override_officiating_priest" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Assigned manually by secretary. Never carried over from parishioner requests.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Marriage Fields -->
                    <div id="marriageFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Marriage record. <strong>Husband's Name</strong>, <strong>Wife's Name</strong>, <strong>Date of Wedding</strong>, <strong>Priest in Charge</strong>, and <strong>Officiating Priest</strong> are required.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Husband's Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_husband_name" id="mar_override_husband_name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Wife's Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_wife_name" id="mar_override_wife_name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Wedding <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_wedding_date" id="mar_override_wedding_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Wedding Location</label>
                                <input type="text" class="form-control form-control-sm" name="override_wedding_location" id="mar_override_wedding_location" placeholder="e.g. San Lorenzo Ruiz Mission Station">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Husband's Residence</label>
                                <input type="text" class="form-control form-control-sm" name="override_husband_residence" id="mar_override_husband_residence">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Wife's Residence</label>
                                <input type="text" class="form-control form-control-sm" name="override_wife_residence" id="mar_override_wife_residence">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Priest in Charge (Parish Priest) <span class="text-danger">*</span></label>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input" name="mar_override_priest_in_charge" id="mar_override_priest_in_charge" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Parish presiding priest. Defaults to current parish priest; fully editable.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input priest-select-highlight" name="mar_override_officiating_priest" id="mar_override_officiating_priest" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Assigned manually by secretary. Never carried over from parishioner requests.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Funeral Fields -->
                    <div id="funeralFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Funeral record. <strong>Deceased Name</strong>, <strong>Date of Burial</strong>, <strong>Priest in Charge</strong>, and <strong>Officiating Priest</strong> are required.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Deceased's Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_deceased_name" id="fun_override_deceased_name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Burial <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_date_of_burial" id="fun_override_date_of_burial">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Place of Burial</label>
                                <input type="text" class="form-control form-control-sm" name="override_place_of_burial" id="fun_override_place_of_burial">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Priest in Charge (Parish Priest) <span class="text-danger">*</span></label>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input" name="fun_override_priest_in_charge" id="fun_override_priest_in_charge" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Parish presiding priest. Defaults to current parish priest; fully editable.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <div class="priest-autocomplete-wrapper">
                                    <input type="text" class="form-control form-control-sm priest-autocomplete-input priest-select-highlight" name="fun_override_officiating_priest" id="fun_override_officiating_priest" placeholder="Type to search or enter priest's name..." list="priestSuggestionsDatalist" autocomplete="off" required>
                                    <div class="priest-autocomplete-dropdown"></div>
                                </div>
                                <div class="form-text text-muted small" style="font-size: 0.72rem;">
                                    Assigned manually by secretary. Never carried over from parishioner requests.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-gold" id="btnSubmitGenerate">
                        <i class="fas fa-file-pdf"></i> Generate Certificate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ACTIVE_PRIESTS = <?php echo json_encode(array_values($active_priests)); ?>;
    const DEFAULT_PARISH_PRIEST = <?php echo json_encode($default_parish_priest); ?>;

    const modalEl = document.getElementById('generateModal');
    const bsModal = new bootstrap.Modal(modalEl);
    const select = document.getElementById('record_id');
    const certTypeInput = document.getElementById('cert_type');
    const baptismFields = document.getElementById('baptismFieldsContainer');
    const sponsorsList = document.getElementById('modalSponsorsList');
    const addSponsorBtn = document.getElementById('addModalSponsorBtn');
    const form = document.getElementById('generatorRecordForm');

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp('(' + escapedQuery + ')', 'gi');
        return escapeHtml(text).replace(regex, '<strong>$1</strong>');
    }

    // Typeahead / Autocomplete handler for priest input fields
    function setupPriestAutocomplete() {
        const inputs = document.querySelectorAll('.priest-autocomplete-input');
        inputs.forEach(input => {
            const wrapper = input.closest('.priest-autocomplete-wrapper');
            if (!wrapper) return;
            let dropdown = wrapper.querySelector('.priest-autocomplete-dropdown');
            if (!dropdown) {
                dropdown = document.createElement('div');
                dropdown.className = 'priest-autocomplete-dropdown';
                wrapper.appendChild(dropdown);
            }

            let activeIndex = -1;

            function renderDropdown(items, query) {
                dropdown.innerHTML = '';
                if (!items || items.length === 0) {
                    if (query && query.trim().length > 0) {
                        const note = document.createElement('div');
                        note.className = 'p-2 text-muted small text-center';
                        note.innerHTML = `<i class="fas fa-pen me-1"></i> Use custom: "<em>${escapeHtml(query)}</em>"`;
                        dropdown.appendChild(note);
                        dropdown.classList.add('show');
                    } else {
                        dropdown.classList.remove('show');
                    }
                    activeIndex = -1;
                    return;
                }

                items.forEach((p, idx) => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'priest-autocomplete-item' + (idx === activeIndex ? ' active' : '');
                    itemEl.setAttribute('data-value', p.name);
                    itemEl.innerHTML = `
                        <div class="text-truncate">
                            <i class="fas fa-user-tie text-secondary me-2"></i>${highlightMatch(p.name, query)}
                        </div>
                        <span class="priest-role-badge">${escapeHtml(p.title || 'Clergy')}</span>
                    `;
                    itemEl.addEventListener('mousedown', function(e) {
                        e.preventDefault(); // Prevent blur before click
                        input.value = p.name;
                        input.classList.remove('is-invalid');
                        dropdown.classList.remove('show');
                        activeIndex = -1;
                    });
                    dropdown.appendChild(itemEl);
                });
                dropdown.classList.add('show');
            }

            function filterPriests(query) {
                const q = (query || '').trim().toLowerCase();
                if (!q) {
                    return ACTIVE_PRIESTS.slice(0, 8);
                }
                return ACTIVE_PRIESTS.filter(p => {
                    const nameMatch = (p.name || '').toLowerCase().includes(q);
                    const titleMatch = (p.title || '').toLowerCase().includes(q);
                    return nameMatch || titleMatch;
                });
            }

            input.addEventListener('input', function() {
                input.classList.remove('is-invalid');
                activeIndex = -1;
                const matches = filterPriests(this.value);
                renderDropdown(matches, this.value);
            });

            input.addEventListener('focus', function() {
                activeIndex = -1;
                const matches = filterPriests(this.value);
                renderDropdown(matches, this.value);
            });

            input.addEventListener('blur', function() {
                // Short timeout to allow click event on dropdown items to fire first
                setTimeout(() => {
                    dropdown.classList.remove('show');
                }, 180);
            });

            input.addEventListener('keydown', function(e) {
                const items = dropdown.querySelectorAll('.priest-autocomplete-item');
                if (!dropdown.classList.contains('show') || items.length === 0) {
                    if (e.key === 'ArrowDown') {
                        const matches = filterPriests(this.value);
                        renderDropdown(matches, this.value);
                    }
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = (activeIndex + 1) % items.length;
                    items.forEach((it, i) => it.classList.toggle('active', i === activeIndex));
                    if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    items.forEach((it, i) => it.classList.toggle('active', i === activeIndex));
                    if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    if (activeIndex >= 0 && items[activeIndex]) {
                        e.preventDefault();
                        input.value = items[activeIndex].getAttribute('data-value');
                        input.classList.remove('is-invalid');
                        dropdown.classList.remove('show');
                        activeIndex = -1;
                    }
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('show');
                    activeIndex = -1;
                }
            });
        });

        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.priest-autocomplete-wrapper')) {
                document.querySelectorAll('.priest-autocomplete-dropdown.show').forEach(d => d.classList.remove('show'));
            }
        });
    }

    setupPriestAutocomplete();

    function updateModalSponsorRemoveButtons() {
        const rows = sponsorsList.querySelectorAll('.modal-sponsor-row');
        rows.forEach((row, idx) => {
            const label = row.querySelector('.modal-sponsor-num');
            if (label) label.textContent = (idx + 1);
            const btn = row.querySelector('.remove-modal-sponsor');
            if (btn) btn.disabled = (rows.length <= 2);
        });
    }

    function createSponsorRow(val, idx) {
        const div = document.createElement('div');
        div.className = 'input-group input-group-sm mb-2 modal-sponsor-row';
        div.innerHTML = `
            <span class="input-group-text"><i class="fas fa-user-check text-secondary"></i> <span class="modal-sponsor-num ms-1">${idx + 1}</span></span>
            <input type="text" name="override_sponsors[]" class="form-control" placeholder="Sponsor Full Name (e.g. Nida Paredes)" value="${val || ''}">
            <button type="button" class="btn btn-outline-danger remove-modal-sponsor" title="Remove sponsor"><i class="fas fa-trash"></i></button>
        `;
        return div;
    }

    if (addSponsorBtn) {
        addSponsorBtn.addEventListener('click', function() {
            const count = sponsorsList.querySelectorAll('.modal-sponsor-row').length;
            const newRow = createSponsorRow('', count);
            sponsorsList.appendChild(newRow);
            updateModalSponsorRemoveButtons();
            
            // Auto-scroll newly added sponsor row into view
            newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            const inp = newRow.querySelector('input');
            if (inp) inp.focus();
        });
    }

    if (sponsorsList) {
        sponsorsList.addEventListener('click', function(e) {
            const btn = e.target.closest('.remove-modal-sponsor');
            if (btn && !btn.disabled) {
                const row = btn.closest('.modal-sponsor-row');
                if (row) {
                    row.remove();
                    updateModalSponsorRemoveButtons();
                }
            }
        });
    }

    // Track which field containers exist
    const communionFields     = document.getElementById('communionFieldsContainer');
    const confirmationFields  = document.getElementById('confirmationFieldsContainer');
    const marriageFields      = document.getElementById('marriageFieldsContainer');
    const funeralFields       = document.getElementById('funeralFieldsContainer');

    function setActiveContainer(activeContainer) {
        const containers = [baptismFields, communionFields, confirmationFields, marriageFields, funeralFields];
        containers.forEach(container => {
            if (!container) return;
            const isActive = (container === activeContainer);
            container.style.display = isActive ? 'block' : 'none';
            container.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = !isActive;
            });
        });
    }

    modalEl.addEventListener('show.bs.modal', function(e) {
        const button = e.relatedTarget;
        const certType = button ? button.getAttribute('data-cert-type') : (certTypeInput.value || 'baptism');
        const recordType = (button ? button.getAttribute('data-record-type') : '') || certType;
        certTypeInput.value = certType;

        const isBaptism      = (certType === 'baptism' || certType === 'baptism_certification');
        const isCommunion    = (certType === 'communion' || certType === 'first_communion_certification');
        const isConfirmation = (certType === 'confirmation' || certType === 'confirmation_certification');
        const isMarriage     = (certType === 'marriage' || certType === 'marriage_certification');
        const isFuneral      = (certType === 'funeral_certification');

        // Reset submit button state
        const submitBtn = document.getElementById('btnSubmitGenerate');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-file-pdf"></i> Generate Certificate';
        }

        if (isBaptism)           setActiveContainer(baptismFields);
        else if (isCommunion)    setActiveContainer(communionFields);
        else if (isConfirmation) setActiveContainer(confirmationFields);
        else if (isMarriage)     setActiveContainer(marriageFields);
        else if (isFuneral)      setActiveContainer(funeralFields);
        else                     setActiveContainer(null);

        // Reset priest inputs: Priest in Charge defaults to Parish Priest; Officiating Priest starts strictly blank
        ['override_priest_in_charge', 'com_override_priest_in_charge', 'conf_override_priest_in_charge', 'mar_override_priest_in_charge', 'fun_override_priest_in_charge'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.value = DEFAULT_PARISH_PRIEST;
                el.classList.remove('is-invalid');
            }
        });
        ['override_officiating_priest', 'com_override_officiating_priest', 'conf_override_officiating_priest', 'mar_override_officiating_priest', 'fun_override_officiating_priest',
         'override_priest', 'com_override_priest', 'conf_override_priest', 'mar_override_priest', 'fun_override_priest'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.value = '';
                el.classList.remove('is-invalid');
            }
        });

        // Reset baptism-specific inputs
        if (isBaptism) {
            ['override_fullname','override_birth_place','override_birth_date','override_residence',
             'override_father_name','override_father_birth_place','override_mother_name',
             'override_mother_birth_place','override_baptism_date'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.value = ''; el.classList.remove('is-invalid'); }
            });
            sponsorsList.innerHTML = '';
        }
        // Reset communion inputs
        if (isCommunion) {
            ['com_override_fullname','com_override_communion_date','com_override_domicile',
             'com_override_parents','com_override_catechist','com_override_principal'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.value = ''; el.classList.remove('is-invalid'); }
            });
        }
        // Reset confirmation inputs
        if (isConfirmation) {
            ['conf_override_fullname','conf_override_confirmation_name','conf_override_confirmation_date',
             'conf_override_sponsor','conf_override_parents'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.value = ''; el.classList.remove('is-invalid'); }
            });
        }
        // Reset marriage inputs
        if (isMarriage) {
            ['mar_override_husband_name','mar_override_wife_name','mar_override_wedding_date',
             'mar_override_wedding_location','mar_override_husband_residence','mar_override_wife_residence'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.value = ''; el.classList.remove('is-invalid'); }
            });
        }
        // Reset funeral inputs
        if (isFuneral) {
            ['fun_override_deceased_name','fun_override_date_of_burial','fun_override_place_of_burial'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.value = ''; el.classList.remove('is-invalid'); }
            });
        }

        select.innerHTML = '<option>Loading...</option>';
        const apiRecordType = (recordType === 'baptism_certification') ? 'baptism'
            : (recordType === 'first_communion_certification' || recordType === 'communion') ? 'communion'
            : (recordType === 'confirmation_certification') ? 'confirmation'
            : (recordType === 'marriage_certification') ? 'marriage'
            : (recordType === 'funeral_certification') ? 'funeral'
            : recordType;
        fetch('../api/get_records.php?type=' + apiRecordType)
            .then(response => response.json())
            .then(data => {
                select.innerHTML = '<option value="">-- Select a record --</option>';
                if (data.success && data.records.length > 0) {
                    data.records.forEach(record => {
                        const option = document.createElement('option');
                        option.value = record.id;
                        option.textContent = record.name;
                        select.appendChild(option);
                    });
                    if (modalEl.dataset.pendingRecordId) {
                        select.value = modalEl.dataset.pendingRecordId;
                        delete modalEl.dataset.pendingRecordId;
                        select.dispatchEvent(new Event('change'));
                    }
                } else {
                    select.innerHTML = '<option value="">No records available</option>';
                }
            })
            .catch(err => {
                console.error('Error loading records:', err);
                select.innerHTML = '<option value="">Error loading records</option>';
            });
    });

    select.addEventListener('change', function() {
        const certType = certTypeInput.value;
        const recId = this.value;
        if (!recId) return;

        const isBaptism      = (certType === 'baptism' || certType === 'baptism_certification');
        const isCommunion    = (certType === 'communion' || certType === 'first_communion_certification');
        const isConfirmation = (certType === 'confirmation' || certType === 'confirmation_certification');
        const isMarriage     = (certType === 'marriage' || certType === 'marriage_certification');
        const isFuneral      = (certType === 'funeral_certification');

        const apiType = isBaptism ? 'baptism'
            : isCommunion ? 'communion'
            : isConfirmation ? 'confirmation'
            : isMarriage ? 'marriage'
            : isFuneral ? 'funeral'
            : '';
        if (!apiType) return;

        fetch('../api/get_record_details.php?type=' + apiType + '&id=' + recId)
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data) return;
                const d = res.data;

                // Populate record fields while keeping Officiating Priest blank for manual secretary assignment
                if (isBaptism) {
                    document.getElementById('override_fullname').value = d.fullname || '';
                    document.getElementById('override_birth_place').value = d.birth_place || '';
                    document.getElementById('override_birth_date').value = d.birth_date || '';
                    document.getElementById('override_residence').value = d.residence || '';
                    document.getElementById('override_father_name').value = d.father_name || '';
                    document.getElementById('override_father_birth_place').value = d.father_birth_place || '';
                    document.getElementById('override_mother_name').value = d.mother_name || '';
                    document.getElementById('override_mother_birth_place').value = d.mother_birth_place || '';
                    document.getElementById('override_baptism_date').value = d.baptism_date || '';
                    const pic = document.getElementById('override_priest_in_charge');
                    if (pic) pic.value = d.parish_priest || d.priest_in_charge || DEFAULT_PARISH_PRIEST;
                    const op = document.getElementById('override_officiating_priest');
                    if (op) op.value = ''; // Always starts blank!

                    sponsorsList.innerHTML = '';
                    const sps = (Array.isArray(d.sponsors) && d.sponsors.length >= 2) ? d.sponsors : ['', ''];
                    sps.forEach((sp, idx) => sponsorsList.appendChild(createSponsorRow(sp, idx)));
                    updateModalSponsorRemoveButtons();
                } else if (isCommunion) {
                    const fld = id => document.getElementById(id);
                    if (fld('com_override_fullname'))         fld('com_override_fullname').value = d.fullname || '';
                    if (fld('com_override_communion_date'))   fld('com_override_communion_date').value = d.communion_date || '';
                    if (fld('com_override_domicile'))         fld('com_override_domicile').value = d.domicile || '';
                    if (fld('com_override_parents'))          fld('com_override_parents').value = d.parents || '';
                    if (fld('com_override_catechist'))        fld('com_override_catechist').value = d.catechist_coordinator || '';
                    if (fld('com_override_principal'))        fld('com_override_principal').value = d.principal || '';
                    if (fld('com_override_priest_in_charge')) fld('com_override_priest_in_charge').value = d.parish_priest || d.priest_in_charge || DEFAULT_PARISH_PRIEST;
                    if (fld('com_override_officiating_priest')) fld('com_override_officiating_priest').value = '';
                } else if (isConfirmation) {
                    const fld = id => document.getElementById(id);
                    if (fld('conf_override_fullname'))             fld('conf_override_fullname').value = d.fullname || '';
                    if (fld('conf_override_confirmation_name'))    fld('conf_override_confirmation_name').value = d.confirmation_name || '';
                    if (fld('conf_override_confirmation_date'))    fld('conf_override_confirmation_date').value = d.confirmation_date || '';
                    if (fld('conf_override_parents'))              fld('conf_override_parents').value = d.parents || '';
                    if (fld('conf_override_sponsor'))              fld('conf_override_sponsor').value = d.sponsor || '';
                    if (fld('conf_override_priest_in_charge'))     fld('conf_override_priest_in_charge').value = d.parish_priest || d.priest_in_charge || DEFAULT_PARISH_PRIEST;
                    if (fld('conf_override_officiating_priest'))   fld('conf_override_officiating_priest').value = '';
                } else if (isMarriage) {
                    const fld = id => document.getElementById(id);
                    if (fld('mar_override_husband_name'))         fld('mar_override_husband_name').value = d.husband_name || '';
                    if (fld('mar_override_wife_name'))            fld('mar_override_wife_name').value = d.wife_name || '';
                    if (fld('mar_override_wedding_date'))         fld('mar_override_wedding_date').value = d.wedding_date || '';
                    if (fld('mar_override_wedding_location'))     fld('mar_override_wedding_location').value = d.wedding_location || '';
                    if (fld('mar_override_husband_residence'))    fld('mar_override_husband_residence').value = d.husband_residence || '';
                    if (fld('mar_override_wife_residence'))       fld('mar_override_wife_residence').value = d.wife_residence || '';
                    if (fld('mar_override_priest_in_charge'))     fld('mar_override_priest_in_charge').value = d.parish_priest || d.priest_in_charge || DEFAULT_PARISH_PRIEST;
                    if (fld('mar_override_officiating_priest'))   fld('mar_override_officiating_priest').value = '';
                } else if (isFuneral) {
                    const fld = id => document.getElementById(id);
                    if (fld('fun_override_deceased_name'))       fld('fun_override_deceased_name').value = d.deceased_name || '';
                    if (fld('fun_override_date_of_burial'))      fld('fun_override_date_of_burial').value = d.date_of_burial || '';
                    if (fld('fun_override_place_of_burial'))     fld('fun_override_place_of_burial').value = d.place_of_burial || '';
                    if (fld('fun_override_priest_in_charge'))     fld('fun_override_priest_in_charge').value = d.parish_priest || d.priest_in_charge || DEFAULT_PARISH_PRIEST;
                    if (fld('fun_override_officiating_priest'))   fld('fun_override_officiating_priest').value = '';
                }
            })
            .catch(err => console.error('Error fetching record details:', err));
    });

    form.addEventListener('submit', function(e) {
        const certType = certTypeInput.value;
        const recId = select.value;
        if (!recId) { e.preventDefault(); alert('Please select a record first.'); return false; }

        const isBaptism      = (certType === 'baptism' || certType === 'baptism_certification');
        const isCommunion    = (certType === 'communion' || certType === 'first_communion_certification');
        const isConfirmation = (certType === 'confirmation' || certType === 'confirmation_certification');
        const isMarriage     = (certType === 'marriage' || certType === 'marriage_certification');
        const isFuneral      = (certType === 'funeral_certification');

        let missing = [];

        if (isBaptism) {
            const bReq = [
                { id: 'override_fullname', label: 'Full Name' },
                { id: 'override_birth_place', label: 'Birthplace' },
                { id: 'override_birth_date', label: 'Birthday' },
                { id: 'override_residence', label: 'Residence' },
                { id: 'override_father_name', label: "Father's Name" },
                { id: 'override_father_birth_place', label: "Father's Birthplace" },
                { id: 'override_mother_name', label: "Mother's Name" },
                { id: 'override_mother_birth_place', label: "Mother's Birthplace" },
                { id: 'override_baptism_date', label: 'Date of Baptism' },
                { id: 'override_priest_in_charge', label: 'Priest in Charge (Parish Priest)' },
                { id: 'override_officiating_priest', label: 'Officiating Priest' }
            ];
            bReq.forEach(f => {
                const el = document.getElementById(f.id);
                if (!el || !el.value.trim()) { missing.push(f.label); if (el) el.classList.add('is-invalid'); }
                else if (el) el.classList.remove('is-invalid');
            });
            const sps = Array.from(sponsorsList.querySelectorAll('input[name="override_sponsors[]"]'))
                .map(i => i.value.trim()).filter(v => v.length > 0);
            if (sps.length < 2) missing.push('At least 2 Sponsors (Ninong-Ninang)');
        } else if (isCommunion) {
            const elName = document.getElementById('com_override_fullname');
            const elDate = document.getElementById('com_override_communion_date');
            const elInCharge = document.getElementById('com_override_priest_in_charge');
            const elOfficiating = document.getElementById('com_override_officiating_priest');
            if (!elName || !elName.value.trim()) { missing.push("Recipient's Full Name"); if (elName) elName.classList.add('is-invalid'); }
            else if (elName) elName.classList.remove('is-invalid');
            if (!elDate || !elDate.value.trim()) { missing.push('Date of First Communion'); if (elDate) elDate.classList.add('is-invalid'); }
            else if (elDate) elDate.classList.remove('is-invalid');
            if (!elInCharge || !elInCharge.value.trim()) { missing.push('Priest in Charge (Parish Priest)'); if (elInCharge) elInCharge.classList.add('is-invalid'); }
            else if (elInCharge) elInCharge.classList.remove('is-invalid');
            if (!elOfficiating || !elOfficiating.value.trim()) { missing.push('Officiating Priest'); if (elOfficiating) elOfficiating.classList.add('is-invalid'); }
            else if (elOfficiating) elOfficiating.classList.remove('is-invalid');
        } else if (isConfirmation) {
            const elName = document.getElementById('conf_override_fullname');
            const elDate = document.getElementById('conf_override_confirmation_date');
            const elInCharge = document.getElementById('conf_override_priest_in_charge');
            const elOfficiating = document.getElementById('conf_override_officiating_priest');
            if (!elName || !elName.value.trim()) { missing.push('Full Name'); if (elName) elName.classList.add('is-invalid'); }
            else if (elName) elName.classList.remove('is-invalid');
            if (!elDate || !elDate.value.trim()) { missing.push('Date of Confirmation'); if (elDate) elDate.classList.add('is-invalid'); }
            else if (elDate) elDate.classList.remove('is-invalid');
            if (!elInCharge || !elInCharge.value.trim()) { missing.push('Priest in Charge (Parish Priest)'); if (elInCharge) elInCharge.classList.add('is-invalid'); }
            else if (elInCharge) elInCharge.classList.remove('is-invalid');
            if (!elOfficiating || !elOfficiating.value.trim()) { missing.push('Officiating Bishop / Priest'); if (elOfficiating) elOfficiating.classList.add('is-invalid'); }
            else if (elOfficiating) elOfficiating.classList.remove('is-invalid');
        } else if (isMarriage) {
            const elH = document.getElementById('mar_override_husband_name');
            const elW = document.getElementById('mar_override_wife_name');
            const elD = document.getElementById('mar_override_wedding_date');
            const elInCharge = document.getElementById('mar_override_priest_in_charge');
            const elOfficiating = document.getElementById('mar_override_officiating_priest');
            if (!elH || !elH.value.trim()) { missing.push("Husband's Name"); if (elH) elH.classList.add('is-invalid'); }
            else if (elH) elH.classList.remove('is-invalid');
            if (!elW || !elW.value.trim()) { missing.push("Wife's Name"); if (elW) elW.classList.add('is-invalid'); }
            else if (elW) elW.classList.remove('is-invalid');
            if (!elD || !elD.value.trim()) { missing.push('Date of Wedding'); if (elD) elD.classList.add('is-invalid'); }
            else if (elD) elD.classList.remove('is-invalid');
            if (!elInCharge || !elInCharge.value.trim()) { missing.push('Priest in Charge (Parish Priest)'); if (elInCharge) elInCharge.classList.add('is-invalid'); }
            else if (elInCharge) elInCharge.classList.remove('is-invalid');
            if (!elOfficiating || !elOfficiating.value.trim()) { missing.push('Officiating Priest'); if (elOfficiating) elOfficiating.classList.add('is-invalid'); }
            else if (elOfficiating) elOfficiating.classList.remove('is-invalid');
        } else if (isFuneral) {
            const elDec = document.getElementById('fun_override_deceased_name');
            const elBur = document.getElementById('fun_override_date_of_burial');
            const elInCharge = document.getElementById('fun_override_priest_in_charge');
            const elOfficiating = document.getElementById('fun_override_officiating_priest');
            if (!elDec || !elDec.value.trim()) { missing.push('Deceased Name'); if (elDec) elDec.classList.add('is-invalid'); }
            else if (elDec) elDec.classList.remove('is-invalid');
            if (!elBur || !elBur.value.trim()) { missing.push('Date of Burial'); if (elBur) elBur.classList.add('is-invalid'); }
            else if (elBur) elBur.classList.remove('is-invalid');
            if (!elInCharge || !elInCharge.value.trim()) { missing.push('Priest in Charge (Parish Priest)'); if (elInCharge) elInCharge.classList.add('is-invalid'); }
            else if (elInCharge) elInCharge.classList.remove('is-invalid');
            if (!elOfficiating || !elOfficiating.value.trim()) { missing.push('Officiating Priest'); if (elOfficiating) elOfficiating.classList.add('is-invalid'); }
            else if (elOfficiating) elOfficiating.classList.remove('is-invalid');
        }

        if (missing.length > 0) {
            e.preventDefault();
            const submitBtn = document.getElementById('btnSubmitGenerate');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-file-pdf"></i> Generate Certificate';
            }
            alert('Cannot generate certificate. Required fields are missing:\n\n• ' + missing.join('\n• '));
            return false;
        }

        // Tactile progress feedback
        const submitBtn = document.getElementById('btnSubmitGenerate');
        if (submitBtn) {
            setTimeout(() => {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Generating...';
            }, 10);
        }
    });

    modalEl.addEventListener('hidden.bs.modal', function() {
        setActiveContainer(null);
        const submitBtn = document.getElementById('btnSubmitGenerate');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-file-pdf"></i> Generate Certificate';
        }
    });

    // Ensure all containers are disabled initially until modal opens
    setActiveContainer(null);

    // Auto-open modal if record_id and cert_type are passed via URL query
    const urlParams = new URLSearchParams(window.location.search);
    const qCertType = urlParams.get('cert_type');
    const qRecordId = urlParams.get('record_id');
    if (qCertType && qRecordId) {
        certTypeInput.value = qCertType;
        modalEl.dataset.pendingRecordId = qRecordId;
        bsModal.show();
    }
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
