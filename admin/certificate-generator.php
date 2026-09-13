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
            
            $fullname = trim($_POST['override_fullname'] ?? ($record['fullname'] ?? ''));
            $father_name = trim($_POST['override_father_name'] ?? ($record['father_name'] ?? ''));
            $mother_name = trim($_POST['override_mother_name'] ?? ($record['mother_name'] ?? ''));
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

            // Validation: Exactly the essential facts per sacrament
            $missing = [];
            if ($fullname === '') $missing[] = 'Full Name of Baptized';
            if ($father_name === '') $missing[] = "Father's Name";
            if ($mother_name === '') $missing[] = "Mother's Name";
            if ($baptism_date === '' || $baptism_date === '0000-00-00') $missing[] = 'Date of Baptism';
            if ($priest === '') $missing[] = 'Officiating Priest';

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please complete them in the form below.';
            } else {
                $record['fullname'] = $fullname;
                $record['father_name'] = $father_name;
                $record['mother_name'] = $mother_name;
                $record['baptism_date'] = $baptism_date;
                $record['priest'] = $priest;

                // Persist updated fields into baptism_records
                $up = $conn->prepare("UPDATE baptism_records SET fullname = ?, father_name = ?, mother_name = ?, baptism_date = ?, priest = ? WHERE baptism_id = ?");
                if ($up) {
                    $up->bind_param('sssssi', $fullname, $father_name, $mother_name, $baptism_date, $priest, $record_id);
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
            $error = 'Baptism record not found or inactive.';
        }
    } elseif ($cert_type == 'communion' || $cert_type == 'first_communion_certification') {
        $stmt = $conn->prepare("SELECT * FROM first_communion_records WHERE communion_id = ? AND (status='active' OR status IS NULL OR status='')");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_fullname  = trim($_POST['com_override_fullname'] ?? ($_POST['override_fullname'] ?? ($record['fullname'] ?? '')));
            $ov_father    = trim($_POST['com_override_father_name'] ?? ($_POST['override_father_name'] ?? ($record['father_name'] ?? '')));
            $ov_mother    = trim($_POST['com_override_mother_name'] ?? ($_POST['override_mother_name'] ?? ($record['mother_name'] ?? '')));
            $ov_comm_date = trim($_POST['override_communion_date'] ?? ($record['communion_date'] ?? ''));
            $ov_priest    = trim($_POST['com_override_priest'] ?? ($_POST['override_priest'] ?? ($record['priest'] ?? ($record['parish_priest'] ?? ''))));

            // Fallback parsing for parents if still blank
            if (empty($ov_father) || empty($ov_mother)) {
                $parents = trim((string)($record['parents'] ?? ''));
                if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $parents, $m)) {
                    if (empty($ov_father)) $ov_father = trim($m[1]);
                    if (empty($ov_mother)) $ov_mother = trim($m[2]);
                } else {
                    $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $parents);
                    $parts = array_values(array_filter(array_map('trim', $parts)));
                    if (count($parts) >= 2) {
                        if (empty($ov_father)) $ov_father = $parts[0];
                        if (empty($ov_mother)) $ov_mother = $parts[1];
                    } elseif (count($parts) === 1 && empty($ov_father)) {
                        $ov_father = $parts[0];
                    }
                }
            }

            $missing = [];
            if ($ov_fullname === '') $missing[] = "Recipient's Full Name";
            if ($ov_father === '') $missing[] = "Father's Name";
            if ($ov_mother === '') $missing[] = "Mother's Name";
            if ($ov_comm_date === '' || $ov_comm_date === '0000-00-00') $missing[] = 'Date of First Communion';
            if ($ov_priest === '') $missing[] = 'Officiating Priest';

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please complete them in the form below.';
            } else {
                $record['fullname'] = $ov_fullname;
                $record['father_name'] = $ov_father;
                $record['mother_name'] = $ov_mother;
                $record['communion_date'] = $ov_comm_date;
                $record['priest'] = $ov_priest;

                // Persist into database
                $up = $conn->prepare("UPDATE first_communion_records SET fullname=?, father_name=?, mother_name=?, communion_date=?, priest=? WHERE communion_id=?");
                if ($up) {
                    $up->bind_param('sssssi', $ov_fullname, $ov_father, $ov_mother, $ov_comm_date, $ov_priest, $record_id);
                    $up->execute();
                    $up->close();
                }

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
            $ov_fullname  = trim($_POST['conf_override_fullname'] ?? ($_POST['override_fullname'] ?? ($record['fullname'] ?? '')));
            $ov_father    = trim($_POST['conf_override_father_name'] ?? ($_POST['override_father_name'] ?? ($record['father_name'] ?? '')));
            $ov_mother    = trim($_POST['conf_override_mother_name'] ?? ($_POST['override_mother_name'] ?? ($record['mother_name'] ?? '')));
            $ov_conf_date = trim($_POST['override_confirmation_date'] ?? ($record['confirmation_date'] ?? ''));
            $ov_priest    = trim($_POST['conf_override_priest'] ?? ($_POST['override_priest'] ?? ($record['bishop_priest'] ?? ($record['parish_priest'] ?? ''))));

            // Fallback parsing for parents if still blank
            if (empty($ov_father) || empty($ov_mother)) {
                $parents = trim((string)($record['parents'] ?? ''));
                if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $parents, $m)) {
                    if (empty($ov_father)) $ov_father = trim($m[1]);
                    if (empty($ov_mother)) $ov_mother = trim($m[2]);
                } else {
                    $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $parents);
                    $parts = array_values(array_filter(array_map('trim', $parts)));
                    if (count($parts) >= 2) {
                        if (empty($ov_father)) $ov_father = $parts[0];
                        if (empty($ov_mother)) $ov_mother = $parts[1];
                    } elseif (count($parts) === 1 && empty($ov_father)) {
                        $ov_father = $parts[0];
                    }
                }
            }

            $missing = [];
            if ($ov_fullname === '') $missing[] = 'Full Name';
            if ($ov_father === '') $missing[] = "Father's Name";
            if ($ov_mother === '') $missing[] = "Mother's Name";
            if ($ov_conf_date === '' || $ov_conf_date === '0000-00-00') $missing[] = 'Date of Confirmation';
            if ($ov_priest === '') $missing[] = 'Confirming Minister / Priest';

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please complete them in the form below.';
            } else {
                $record['fullname'] = $ov_fullname;
                $record['father_name'] = $ov_father;
                $record['mother_name'] = $ov_mother;
                $record['confirmation_date'] = $ov_conf_date;
                $record['bishop_priest'] = $ov_priest;

                // Persist into database
                $up = $conn->prepare("UPDATE confirmation_records SET fullname=?, father_name=?, mother_name=?, confirmation_date=?, bishop_priest=? WHERE confirmation_id=?");
                if ($up) {
                    $up->bind_param('sssssi', $ov_fullname, $ov_father, $ov_mother, $ov_conf_date, $ov_priest, $record_id);
                    $up->execute();
                    $up->close();
                }

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
            $ov_husband  = trim($_POST['override_husband_name'] ?? ($record['husband_name'] ?? ''));
            $ov_wife     = trim($_POST['override_wife_name'] ?? ($record['wife_name'] ?? ''));
            $ov_wed_date = trim($_POST['override_wedding_date'] ?? ($record['wedding_date'] ?? ''));
            $ov_priest   = trim($_POST['mar_override_priest'] ?? ($_POST['override_priest'] ?? ($record['officiating_priest'] ?? ($record['parish_priest'] ?? ''))));

            $missing = [];
            if ($ov_husband === '') $missing[] = "Groom's Full Name";
            if ($ov_wife === '') $missing[] = "Bride's Full Name";
            if ($ov_wed_date === '' || $ov_wed_date === '0000-00-00') $missing[] = 'Date of Marriage';
            if ($ov_priest === '') $missing[] = 'Officiating Priest';

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please complete them in the form below.';
            } else {
                $record['husband_name'] = $ov_husband;
                $record['wife_name'] = $ov_wife;
                $record['wedding_date'] = $ov_wed_date;
                $record['officiating_priest'] = $ov_priest;

                // Persist into database
                $up = $conn->prepare("UPDATE marriage_records SET husband_name=?, wife_name=?, wedding_date=?, officiating_priest=? WHERE marriage_id=?");
                if ($up) {
                    $up->bind_param('ssssi', $ov_husband, $ov_wife, $ov_wed_date, $ov_priest, $record_id);
                    $up->execute();
                    $up->close();
                }

                unset($_SESSION['manual_certificate']);
                $_SESSION['certificate_data'] = $record;
                $_SESSION['cert_type'] = $cert_type;
                header('Location: view-certificate.php?id=' . $record_id . '&type=' . urlencode($cert_type));
                exit;
            }
        } else { $error = 'Marriage record not found or inactive.'; }
    } elseif ($cert_type == 'funeral' || $cert_type == 'funeral_certification') {
        $stmt = $conn->prepare("SELECT * FROM funeral_records WHERE funeral_id = ? AND (status='active' OR status IS NULL OR status='')");
        if ($stmt) { $stmt->bind_param('i', $record_id); $stmt->execute(); $result = $stmt->get_result(); $stmt->close(); }
        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            $ov_deceased    = trim($_POST['override_deceased_name'] ?? ($record['deceased_name'] ?? ''));
            $ov_father      = trim($_POST['fun_override_father_name'] ?? ($_POST['override_father_name'] ?? ($record['father_name'] ?? '')));
            $ov_mother      = trim($_POST['fun_override_mother_name'] ?? ($_POST['override_mother_name'] ?? ($record['mother_name'] ?? '')));
            $ov_burial_date = trim($_POST['override_date_of_burial'] ?? ($record['date_of_burial'] ?? ''));
            $ov_priest      = trim($_POST['fun_override_priest'] ?? ($_POST['override_priest'] ?? ($record['minister'] ?? ($record['parish_priest'] ?? ''))));

            // Fallback parsing for parents if still blank
            if (empty($ov_father) || empty($ov_mother)) {
                $parents = trim((string)($record['parents'] ?? ''));
                if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $parents, $m)) {
                    if (empty($ov_father)) $ov_father = trim($m[1]);
                    if (empty($ov_mother)) $ov_mother = trim($m[2]);
                } else {
                    $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $parents);
                    $parts = array_values(array_filter(array_map('trim', $parts)));
                    if (count($parts) >= 2) {
                        if (empty($ov_father)) $ov_father = $parts[0];
                        if (empty($ov_mother)) $ov_mother = $parts[1];
                    } elseif (count($parts) === 1 && empty($ov_father)) {
                        $ov_father = $parts[0];
                    }
                }
            }

            $missing = [];
            if ($ov_deceased === '') $missing[] = 'Full Name of the Deceased';
            if ($ov_father === '') $missing[] = "Father's Name";
            if ($ov_mother === '') $missing[] = "Mother's Name";
            if ($ov_burial_date === '' || $ov_burial_date === '0000-00-00') $missing[] = 'Date of Burial/Funeral Rites';
            if ($ov_priest === '') $missing[] = 'Officiating Priest';

            if (!empty($missing)) {
                $error = 'Cannot generate certificate. Required fields are missing: ' . implode(', ', $missing) . '. Please complete them in the form below.';
            } else {
                $record['deceased_name'] = $ov_deceased;
                $record['father_name'] = $ov_father;
                $record['mother_name'] = $ov_mother;
                $record['date_of_burial'] = $ov_burial_date;
                $record['minister'] = $ov_priest;

                // Persist into database
                $up = $conn->prepare("UPDATE funeral_records SET deceased_name=?, father_name=?, mother_name=?, date_of_burial=?, minister=? WHERE funeral_id=?");
                if ($up) {
                    $up->bind_param('sssssi', $ov_deceased, $ov_father, $ov_mother, $ov_burial_date, $ov_priest, $record_id);
                    $up->execute();
                    $up->close();
                }

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
                            <div>Baptismal Certification. All 5 essential fields below are required before generating.</div>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Full Name of Baptized <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_fullname" id="override_fullname" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_father_name" id="override_father_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Mother's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_mother_name" id="override_mother_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Baptism <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_baptism_date" id="override_baptism_date" required>
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
                            </div>
                        </div>
                    </div>

                    <!-- First Communion Fields -->
                    <div id="communionFieldsContainer" style="display:none;">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                            <i class="fas fa-circle-info fs-5"></i>
                            <div>First Communion Certification. All 5 essential fields below are required before generating.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Full Name of Communicant <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="com_override_fullname" id="com_override_fullname" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="com_override_father_name" id="com_override_father_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Mother's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="com_override_mother_name" id="com_override_mother_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of First Communion <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_communion_date" id="com_override_communion_date" required>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="com_override_priest" id="com_override_priest" required>
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
                            <div>Confirmation Certification. All 5 essential fields below are required before generating.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Full Name of Confirmed <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="conf_override_fullname" id="conf_override_fullname" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="conf_override_father_name" id="conf_override_father_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Mother's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="conf_override_mother_name" id="conf_override_mother_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Confirmation <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_confirmation_date" id="conf_override_confirmation_date" required>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Confirming Minister / Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="conf_override_priest" id="conf_override_priest" required>
                                    <option value="">-- Select Officiating Minister --</option>
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
                            <div>Marriage Certification. All 4 essential fields below are required before generating.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Groom's Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_husband_name" id="mar_override_husband_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Bride's Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_wife_name" id="mar_override_wife_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Marriage <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_wedding_date" id="mar_override_wedding_date" required>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="mar_override_priest" id="mar_override_priest" required>
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
                            <div>Funeral / Burial Certification. All 5 essential fields below are required before generating.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Full Name of the Deceased <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_deceased_name" id="fun_override_deceased_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Father's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="fun_override_father_name" id="fun_override_father_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Mother's Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="fun_override_mother_name" id="fun_override_mother_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Date of Burial / Funeral Rites <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" name="override_date_of_burial" id="fun_override_date_of_burial" required>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">Officiating Priest <span class="text-danger">*</span></label>
                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size:0.68rem;"><i class="fas fa-hand-pointer me-1"></i> Secretary Assigned</span>
                                </div>
                                <select class="form-select form-select-sm priest-select-highlight" name="fun_override_priest" id="fun_override_priest" required>
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

        // Reset inputs
        if (isBaptism) {
            ['override_fullname','override_father_name','override_mother_name','override_baptism_date'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('override_priest'));
        }
        if (isCommunion) {
            ['com_override_fullname','com_override_father_name','com_override_mother_name','com_override_communion_date'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('com_override_priest'));
        }
        if (isConfirmation) {
            ['conf_override_fullname','conf_override_father_name','conf_override_mother_name','conf_override_confirmation_date'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('conf_override_priest'));
        }
        if (isMarriage) {
            ['mar_override_husband_name','mar_override_wife_name','mar_override_wedding_date'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            setDefaultPriest(document.getElementById('mar_override_priest'));
        }
        if (isFuneral) {
            ['fun_override_deceased_name','fun_override_father_name','fun_override_mother_name','fun_override_date_of_burial'].forEach(id => {
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
        const isFuneral      = (certType === 'funeral' || certType === 'funeral_certification');

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
                const fld = id => document.getElementById(id);

                if (isBaptism) {
                    if (fld('override_fullname'))     fld('override_fullname').value = d.fullname || '';
                    if (fld('override_father_name'))  fld('override_father_name').value = d.father_name || '';
                    if (fld('override_mother_name'))  fld('override_mother_name').value = d.mother_name || '';
                    if (fld('override_baptism_date')) fld('override_baptism_date').value = d.baptism_date || '';
                    const ps = document.getElementById('override_priest');
                    d.priest ? setPriestValue(ps, d.priest) : setDefaultPriest(ps);
                } else if (isCommunion) {
                    if (fld('com_override_fullname'))    fld('com_override_fullname').value = d.fullname || '';
                    if (fld('com_override_father_name')) fld('com_override_father_name').value = d.father_name || '';
                    if (fld('com_override_mother_name')) fld('com_override_mother_name').value = d.mother_name || '';
                    if (fld('com_override_communion_date')) fld('com_override_communion_date').value = d.communion_date || '';
                    const cp = document.getElementById('com_override_priest');
                    d.priest ? setPriestValue(cp, d.priest) : setDefaultPriest(cp);
                } else if (isConfirmation) {
                    if (fld('conf_override_fullname'))    fld('conf_override_fullname').value = d.fullname || '';
                    if (fld('conf_override_father_name')) fld('conf_override_father_name').value = d.father_name || '';
                    if (fld('conf_override_mother_name')) fld('conf_override_mother_name').value = d.mother_name || '';
                    if (fld('conf_override_confirmation_date')) fld('conf_override_confirmation_date').value = d.confirmation_date || '';
                    const cp = document.getElementById('conf_override_priest');
                    d.bishop_priest ? setPriestValue(cp, d.bishop_priest) : setDefaultPriest(cp);
                } else if (isMarriage) {
                    if (fld('mar_override_husband_name')) fld('mar_override_husband_name').value = d.husband_name || '';
                    if (fld('mar_override_wife_name'))    fld('mar_override_wife_name').value = d.wife_name || '';
                    if (fld('mar_override_wedding_date')) fld('mar_override_wedding_date').value = d.wedding_date || '';
                    const mp = document.getElementById('mar_override_priest');
                    d.officiating_priest ? setPriestValue(mp, d.officiating_priest) : setDefaultPriest(mp);
                } else if (isFuneral) {
                    if (fld('fun_override_deceased_name')) fld('fun_override_deceased_name').value = d.deceased_name || '';
                    if (fld('fun_override_father_name'))   fld('fun_override_father_name').value = d.father_name || '';
                    if (fld('fun_override_mother_name'))   fld('fun_override_mother_name').value = d.mother_name || '';
                    if (fld('fun_override_date_of_burial')) fld('fun_override_date_of_burial').value = d.date_of_burial || '';
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
        const isFuneral      = (certType === 'funeral' || certType === 'funeral_certification');

        let missing = [];

        function checkField(id, label) {
            const el = document.getElementById(id);
            if (!el || !el.value.trim() || el.value === '0000-00-00') {
                missing.push(label);
                if (el) el.classList.add('is-invalid');
            } else if (el) {
                el.classList.remove('is-invalid');
            }
        }

        if (isBaptism) {
            checkField('override_fullname', 'Full Name of Baptized');
            checkField('override_father_name', "Father's Name");
            checkField('override_mother_name', "Mother's Name");
            checkField('override_baptism_date', 'Date of Baptism');
            checkField('override_priest', 'Officiating Priest');
        } else if (isCommunion) {
            checkField('com_override_fullname', "Recipient's Full Name");
            checkField('com_override_father_name', "Father's Name");
            checkField('com_override_mother_name', "Mother's Name");
            checkField('com_override_communion_date', 'Date of First Communion');
            checkField('com_override_priest', 'Officiating Priest');
        } else if (isConfirmation) {
            checkField('conf_override_fullname', 'Full Name of Confirmed');
            checkField('conf_override_father_name', "Father's Name");
            checkField('conf_override_mother_name', "Mother's Name");
            checkField('conf_override_confirmation_date', 'Date of Confirmation');
            checkField('conf_override_priest', 'Confirming Minister / Priest');
        } else if (isMarriage) {
            checkField('mar_override_husband_name', "Groom's Full Name");
            checkField('mar_override_wife_name', "Bride's Full Name");
            checkField('mar_override_wedding_date', 'Date of Marriage');
            checkField('mar_override_priest', 'Officiating Priest');
        } else if (isFuneral) {
            checkField('fun_override_deceased_name', 'Full Name of the Deceased');
            checkField('fun_override_father_name', "Father's Name");
            checkField('fun_override_mother_name', "Mother's Name");
            checkField('fun_override_date_of_burial', 'Date of Burial / Funeral Rites');
            checkField('fun_override_priest', 'Officiating Priest');
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

<?php include '../templates/footer.php'; ?>
