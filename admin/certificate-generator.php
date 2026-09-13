<?php
/**
 * Certificate Generator Module - Builds sacramental certificate data for preview, print, and verification.
 */
require_once '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

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
            $priest = trim($_POST['override_priest'] ?? ($record['priest'] ?? ''));

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
            if ($priest === '') $missing[] = 'Officiating Priest';
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
                $record['priest'] = $priest;
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
                header('Location: view-certificate.php');
                exit;
            }
        } else {
            $stmt->close();
        }
    } elseif ($cert_type == 'communion' || $cert_type == 'first_communion_certification') {
        $stmt = $conn->prepare("SELECT * FROM first_communion_records WHERE communion_id = ? AND status='active'");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            // Apply overrides from form
            $ov_fullname      = trim($_POST['override_fullname'] ?? '');
            $ov_comm_date     = trim($_POST['override_communion_date'] ?? '');
            $ov_domicile      = trim($_POST['override_domicile'] ?? '');
            $ov_parents       = trim($_POST['override_parents'] ?? '');
            $ov_priest        = trim($_POST['override_priest'] ?? '');
            $ov_catechist     = trim($_POST['override_catechist_coordinator'] ?? '');
            $ov_principal     = trim($_POST['override_principal'] ?? '');
            if ($ov_fullname)  $record['fullname']              = $ov_fullname;
            if ($ov_comm_date) $record['communion_date']        = $ov_comm_date;
            if ($ov_domicile)  $record['domicile']              = $ov_domicile;
            if ($ov_parents)   $record['parents']               = $ov_parents;
            if ($ov_priest)    $record['priest'] = $record['parish_priest'] = $ov_priest;
            if ($ov_catechist) $record['catechist_coordinator'] = $ov_catechist;
            if ($ov_principal) $record['principal']             = $ov_principal;
            $missing = [];
            if (empty($record['fullname']))      $missing[] = "Recipient's Full Name";
            if (empty($record['communion_date'])) $missing[] = 'Date of First Communion';
            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please complete the form below.';
            } else {
                $signers = getFirstCommunionSigners($conn, $record);
                if (empty($record['catechist_coordinator'])) $record['catechist_coordinator'] = $signers['catechist_coordinator'];
                if (empty($record['parish_priest']))          $record['parish_priest']          = $signers['parish_priest'];
                if (empty($record['principal']))              $record['principal']              = $signers['principal'];
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php');
                exit;
            }
        } else { $error = 'Communion record not found or inactive.'; }
    } elseif ($cert_type == 'confirmation' || $cert_type == 'confirmation_certification') {
        $stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ? AND status='active'");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_fullname     = trim($_POST['override_fullname'] ?? '');
            $ov_conf_name    = trim($_POST['override_confirmation_name'] ?? '');
            $ov_conf_date    = trim($_POST['override_confirmation_date'] ?? '');
            $ov_parents      = trim($_POST['override_parents'] ?? '');
            $ov_sponsor      = trim($_POST['override_sponsor'] ?? '');
            $ov_priest       = trim($_POST['override_priest'] ?? '');
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
            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '.';
            } else {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php');
                exit;
            }
        } else { $error = 'Confirmation record not found or inactive.'; }
    } elseif ($cert_type == 'marriage' || $cert_type == 'marriage_certification') {
        $stmt = $conn->prepare("SELECT * FROM marriage_records WHERE marriage_id = ? AND status='active'");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_husband     = trim($_POST['override_husband_name'] ?? '');
            $ov_wife        = trim($_POST['override_wife_name'] ?? '');
            $ov_wed_date    = trim($_POST['override_wedding_date'] ?? '');
            $ov_wed_loc     = trim($_POST['override_wedding_location'] ?? '');
            $ov_priest      = trim($_POST['override_priest'] ?? '');
            $ov_h_residence = trim($_POST['override_husband_residence'] ?? '');
            $ov_w_residence = trim($_POST['override_wife_residence'] ?? '');
            if ($ov_husband)     $record['husband_name']        = $ov_husband;
            if ($ov_wife)        $record['wife_name']           = $ov_wife;
            if ($ov_wed_date)    $record['wedding_date']        = $ov_wed_date;
            if ($ov_wed_loc)     $record['wedding_location']    = $ov_wed_loc;
            if ($ov_priest)      $record['officiating_priest'] = $record['parish_priest'] = $ov_priest;
            if ($ov_h_residence) $record['husband_residence']   = $ov_h_residence;
            if ($ov_w_residence) $record['wife_residence']      = $ov_w_residence;
            $missing = [];
            if (empty($record['husband_name'])) $missing[] = "Husband's Name";
            if (empty($record['wife_name']))    $missing[] = "Wife's Name";
            if (empty($record['wedding_date'])) $missing[] = 'Date of Wedding';
            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '.';
            } else {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php');
                exit;
            }
        } else { $error = 'Marriage record not found or inactive.'; }
    } elseif ($cert_type == 'funeral_certification') {
        $stmt = $conn->prepare("SELECT * FROM funeral_records WHERE funeral_id = ? AND status='active'");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_deceased     = trim($_POST['override_deceased_name'] ?? '');
            $ov_burial_date  = trim($_POST['override_date_of_burial'] ?? '');
            $ov_burial_place = trim($_POST['override_place_of_burial'] ?? '');
            $ov_priest       = trim($_POST['override_priest'] ?? '');
            if ($ov_deceased)     $record['deceased_name']   = $ov_deceased;
            if ($ov_burial_date)  $record['date_of_burial']  = $ov_burial_date;
            if ($ov_burial_place) $record['place_of_burial'] = $ov_burial_place;
            if ($ov_priest)       $record['minister']        = $ov_priest;
            $missing = [];
            if (empty($record['deceased_name']))  $missing[] = 'Deceased Name';
            if (empty($record['date_of_burial'])) $missing[] = 'Date of Burial';
            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '.';
            } else {
                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php');
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

$page_title = 'Certificate Generator';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Certificate Generator' => null
];

include '../templates/header.php';
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
        $page_header_icon = 'fa-certificate';
        $show_back_button = true;
        $back_button_url = BASE_URL . 'admin/dashboard.php';
        include '../includes/page_header.php';
        ?>
        <div class="mb-3">
            <a href="manual-certificate-generator.php" class="btn btn-primary-gold">
                <i class="fas fa-pen-to-square"></i> Manual Certificate Generator
            </a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger mb-3"><i class="fas fa-circle-exclamation me-2"></i><?php echo e($error); ?></div>
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

<!-- Generate Certificate Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="generateModalTitle"><i class="fas fa-file-pdf me-2 text-warning"></i> Generate Certificate</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="generatorRecordForm">
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
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Baptism <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_baptism_date" id="override_baptism_date">
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
                                <select class="form-select form-select-sm priest-select-highlight" name="override_priest" id="override_priest" required>
                                    <option value="">-- Select Officiating Priest --</option>
                                    <?php foreach ($active_priests as $p): ?>
                                        <option value="<?php echo e($p['name']); ?>" <?php echo (!empty($p['is_default'])) ? 'data-default="1"' : ''; ?>>
                                            <?php echo e($p['name']); ?> (<?php echo e($p['title']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text text-muted small" style="font-size: 0.75rem;">
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
                            <div>First Communion record. Verify and complete all required fields. <strong>Full Name</strong> and <strong>Date of First Communion</strong> are required.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Full Name of Communicant <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_fullname" id="com_override_fullname">
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
                                <input type="text" class="form-control form-control-sm" name="override_parents" id="com_override_parents" placeholder="e.g. Juan Dela Cruz and Maria Santos">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Catechist Coordinator</label>
                                <input type="text" class="form-control form-control-sm" name="override_catechist_coordinator" id="com_override_catechist" placeholder="e.g. Sis. Lourdes Fernandez">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Principal</label>
                                <input type="text" class="form-control form-control-sm" name="override_principal" id="com_override_principal" placeholder="e.g. Principal Name">
                            </div>
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="override_priest" id="com_override_priest">
                                    <option value="">-- Select Officiating Priest --</option>
                                    <?php foreach ($active_priests as $p): ?>
                                        <option value="<?php echo e($p['name']); ?>" <?php echo (!empty($p['is_default'])) ? 'data-default="1"' : ''; ?>>
                                            <?php echo e($p['name']); ?> (<?php echo e($p['title']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Confirmation Fields -->
                    <div id="confirmationFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Confirmation record. Verify all fields. <strong>Full Name</strong>, <strong>Date of Confirmation</strong>, and <strong>Officiating Priest</strong> are required.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_fullname" id="conf_override_fullname">
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
                                <input type="text" class="form-control form-control-sm" name="override_parents" id="conf_override_parents" placeholder="e.g. Juan Dela Cruz and Maria Santos">
                            </div>
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Bishop / Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="override_priest" id="conf_override_priest">
                                    <option value="">-- Select Officiating Priest --</option>
                                    <?php foreach ($active_priests as $p): ?>
                                        <option value="<?php echo e($p['name']); ?>" <?php echo (!empty($p['is_default'])) ? 'data-default="1"' : ''; ?>>
                                            <?php echo e($p['name']); ?> (<?php echo e($p['title']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Marriage Fields -->
                    <div id="marriageFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Marriage record. <strong>Husband's Name</strong>, <strong>Wife's Name</strong>, and <strong>Date of Wedding</strong> are required.</div>
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
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="override_priest" id="mar_override_priest">
                                    <option value="">-- Select Officiating Priest --</option>
                                    <?php foreach ($active_priests as $p): ?>
                                        <option value="<?php echo e($p['name']); ?>" <?php echo (!empty($p['is_default'])) ? 'data-default="1"' : ''; ?>>
                                            <?php echo e($p['name']); ?> (<?php echo e($p['title']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Funeral Fields -->
                    <div id="funeralFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>Funeral record. <strong>Deceased Name</strong> and <strong>Date of Burial</strong> are required.</div>
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
                            <div class="col-md-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="override_priest" id="fun_override_priest">
                                    <option value="">-- Select Officiating Priest --</option>
                                    <?php foreach ($active_priests as $p): ?>
                                        <option value="<?php echo e($p['name']); ?>" <?php echo (!empty($p['is_default'])) ? 'data-default="1"' : ''; ?>>
                                            <?php echo e($p['name']); ?> (<?php echo e($p['title']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
    const modalEl = document.getElementById('generateModal');
    const bsModal = new bootstrap.Modal(modalEl);
    const select = document.getElementById('record_id');
    const certTypeInput = document.getElementById('cert_type');
    const baptismFields = document.getElementById('baptismFieldsContainer');
    const sponsorsList = document.getElementById('modalSponsorsList');
    const addSponsorBtn = document.getElementById('addModalSponsorBtn');
    const form = document.getElementById('generatorRecordForm');

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

    function hideAllFieldContainers() {
        [baptismFields, communionFields, confirmationFields, marriageFields, funeralFields]
            .forEach(el => { if (el) el.style.display = 'none'; });
    }

    function setDefaultPriest(selectEl) {
        if (!selectEl) return;
        const defOpt = selectEl.querySelector('option[data-default="1"]');
        if (defOpt) selectEl.value = defOpt.value;
        else if (selectEl.options.length > 1) selectEl.selectedIndex = 1;
    }

    function setPriestValue(selectEl, priestName) {
        if (!selectEl || !priestName) return;
        let found = false;
        for (let opt of selectEl.options) {
            if (opt.value.toLowerCase().trim() === priestName.toLowerCase().trim()) {
                selectEl.value = opt.value;
                found = true;
                break;
            }
        }
        if (!found && priestName) {
            const newOpt = document.createElement('option');
            newOpt.value = priestName;
            newOpt.textContent = priestName + ' (From Record)';
            selectEl.appendChild(newOpt);
            selectEl.value = priestName;
        }
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

        hideAllFieldContainers();
        if (isBaptism      && baptismFields)      baptismFields.style.display = 'block';
        if (isCommunion    && communionFields)    communionFields.style.display = 'block';
        if (isConfirmation && confirmationFields) confirmationFields.style.display = 'block';
        if (isMarriage     && marriageFields)     marriageFields.style.display = 'block';
        if (isFuneral      && funeralFields)      funeralFields.style.display = 'block';

        // Reset baptism-specific inputs
        if (isBaptism) {
            ['override_fullname','override_birth_place','override_birth_date','override_residence',
             'override_father_name','override_father_birth_place','override_mother_name',
             'override_mother_birth_place','override_baptism_date'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('override_priest'));
            sponsorsList.innerHTML = '';
        }
        // Reset communion inputs
        if (isCommunion) {
            ['com_override_fullname','com_override_communion_date','com_override_domicile',
             'com_override_parents','com_override_catechist','com_override_principal'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('com_override_priest'));
        }
        // Reset confirmation inputs
        if (isConfirmation) {
            ['conf_override_fullname','conf_override_confirmation_name','conf_override_confirmation_date',
             'conf_override_sponsor','conf_override_parents'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('conf_override_priest'));
        }
        // Reset marriage inputs
        if (isMarriage) {
            ['mar_override_husband_name','mar_override_wife_name','mar_override_wedding_date',
             'mar_override_wedding_location','mar_override_husband_residence','mar_override_wife_residence'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('mar_override_priest'));
        }
        // Reset funeral inputs
        if (isFuneral) {
            ['fun_override_deceased_name','fun_override_date_of_burial','fun_override_place_of_burial'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('fun_override_priest'));
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
                    const ps = document.getElementById('override_priest');
                    d.priest ? setPriestValue(ps, d.priest) : setDefaultPriest(ps);
                    sponsorsList.innerHTML = '';
                    const sps = (Array.isArray(d.sponsors) && d.sponsors.length >= 2) ? d.sponsors : ['', ''];
                    sps.forEach((sp, idx) => sponsorsList.appendChild(createSponsorRow(sp, idx)));
                    updateModalSponsorRemoveButtons();
                } else if (isCommunion) {
                    const fld = id => document.getElementById(id);
                    if (fld('com_override_fullname'))       fld('com_override_fullname').value = d.fullname || '';
                    if (fld('com_override_communion_date')) fld('com_override_communion_date').value = d.communion_date || '';
                    if (fld('com_override_domicile'))       fld('com_override_domicile').value = d.domicile || '';
                    if (fld('com_override_parents'))        fld('com_override_parents').value = d.parents || '';
                    if (fld('com_override_catechist'))      fld('com_override_catechist').value = d.catechist_coordinator || '';
                    if (fld('com_override_principal'))      fld('com_override_principal').value = d.principal || '';
                    const cp = document.getElementById('com_override_priest');
                    d.priest ? setPriestValue(cp, d.priest) : setDefaultPriest(cp);
                } else if (isConfirmation) {
                    const fld = id => document.getElementById(id);
                    if (fld('conf_override_fullname'))           fld('conf_override_fullname').value = d.fullname || '';
                    if (fld('conf_override_confirmation_name'))  fld('conf_override_confirmation_name').value = d.confirmation_name || '';
                    if (fld('conf_override_confirmation_date'))  fld('conf_override_confirmation_date').value = d.confirmation_date || '';
                    if (fld('conf_override_parents'))            fld('conf_override_parents').value = d.parents || '';
                    if (fld('conf_override_sponsor'))            fld('conf_override_sponsor').value = d.sponsor || '';
                    const cp = document.getElementById('conf_override_priest');
                    d.bishop_priest ? setPriestValue(cp, d.bishop_priest) : setDefaultPriest(cp);
                } else if (isMarriage) {
                    const fld = id => document.getElementById(id);
                    if (fld('mar_override_husband_name'))       fld('mar_override_husband_name').value = d.husband_name || '';
                    if (fld('mar_override_wife_name'))          fld('mar_override_wife_name').value = d.wife_name || '';
                    if (fld('mar_override_wedding_date'))       fld('mar_override_wedding_date').value = d.wedding_date || '';
                    if (fld('mar_override_wedding_location'))   fld('mar_override_wedding_location').value = d.wedding_location || '';
                    if (fld('mar_override_husband_residence'))  fld('mar_override_husband_residence').value = d.husband_residence || '';
                    if (fld('mar_override_wife_residence'))     fld('mar_override_wife_residence').value = d.wife_residence || '';
                    const mp = document.getElementById('mar_override_priest');
                    d.officiating_priest ? setPriestValue(mp, d.officiating_priest) : setDefaultPriest(mp);
                } else if (isFuneral) {
                    const fld = id => document.getElementById(id);
                    if (fld('fun_override_deceased_name'))   fld('fun_override_deceased_name').value = d.deceased_name || '';
                    if (fld('fun_override_date_of_burial'))  fld('fun_override_date_of_burial').value = d.date_of_burial || '';
                    if (fld('fun_override_place_of_burial')) fld('fun_override_place_of_burial').value = d.place_of_burial || '';
                    const fp = document.getElementById('fun_override_priest');
                    d.minister ? setPriestValue(fp, d.minister) : setDefaultPriest(fp);
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
                { id: 'override_priest', label: 'Officiating Priest' }
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
            if (!document.getElementById('com_override_fullname')?.value.trim()) missing.push("Recipient's Full Name");
            if (!document.getElementById('com_override_communion_date')?.value.trim()) missing.push('Date of First Communion');
        } else if (isConfirmation) {
            if (!document.getElementById('conf_override_fullname')?.value.trim()) missing.push('Full Name');
            if (!document.getElementById('conf_override_confirmation_date')?.value.trim()) missing.push('Date of Confirmation');
            if (!document.getElementById('conf_override_priest')?.value.trim()) missing.push('Officiating Priest');
        } else if (isMarriage) {
            if (!document.getElementById('mar_override_husband_name')?.value.trim()) missing.push("Husband's Name");
            if (!document.getElementById('mar_override_wife_name')?.value.trim()) missing.push("Wife's Name");
            if (!document.getElementById('mar_override_wedding_date')?.value.trim()) missing.push('Date of Wedding');
        } else if (isFuneral) {
            if (!document.getElementById('fun_override_deceased_name')?.value.trim()) missing.push('Deceased Name');
            if (!document.getElementById('fun_override_date_of_burial')?.value.trim()) missing.push('Date of Burial');
        }

        if (missing.length > 0) {
            e.preventDefault();
            alert('Cannot generate certificate. Required fields are missing:\n\n• ' + missing.join('\n• '));
            return false;
        }
    });

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

<?php include '../templates/footer.php'; ?>
