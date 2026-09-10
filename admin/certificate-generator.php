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
        $sql = "SELECT * FROM first_communion_records WHERE communion_id = $record_id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $record = $result->fetch_assoc();
            unset($_SESSION['manual_certificate']);
            $_SESSION['certificate_data'] = $record;
            $_SESSION['cert_type'] = $cert_type;
            header('Location: view-certificate.php');
            exit;
        }
    } elseif ($cert_type == 'confirmation' || $cert_type == 'confirmation_certification') {
        $sql = "SELECT * FROM confirmation_records WHERE confirmation_id = $record_id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $record = $result->fetch_assoc();
            unset($_SESSION['manual_certificate']);
            $_SESSION['certificate_data'] = $record;
            $_SESSION['cert_type'] = $cert_type;
            header('Location: view-certificate.php');
            exit;
        }
    } elseif ($cert_type == 'marriage' || $cert_type == 'marriage_certification') {
        $sql = "SELECT * FROM marriage_records WHERE marriage_id = $record_id";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            $record = $result->fetch_assoc();
            unset($_SESSION['manual_certificate']);
            $_SESSION['certificate_data'] = $record;
            $_SESSION['cert_type'] = $cert_type;
            header('Location: view-certificate.php');
            exit;
        }
    } elseif ($cert_type == 'funeral_certification') {
        $sql = "SELECT * FROM funeral_records WHERE funeral_id = $record_id";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $record = $result->fetch_assoc();
            unset($_SESSION['manual_certificate']);
            $_SESSION['certificate_data'] = $record;
            $_SESSION['cert_type'] = $cert_type;
            header('Location: view-certificate.php');
            exit;
        }
    }
}

// Get available records
$baptism_count = $conn->query("SELECT COUNT(*) as count FROM baptism_records WHERE status='active'")->fetch_assoc()['count'];
$communion_count = $conn->query("SELECT COUNT(*) as count FROM first_communion_records WHERE status='active'")->fetch_assoc()['count'];
$confirmation_count = $conn->query("SELECT COUNT(*) as count FROM confirmation_records WHERE status='active'")->fetch_assoc()['count'];
$marriage_count = $conn->query("SELECT COUNT(*) as count FROM marriage_records WHERE status='active'")->fetch_assoc()['count'];
$funeral_count = $conn->query("SELECT COUNT(*) as count FROM funeral_records WHERE status='active'")->fetch_assoc()['count'];

$page_title = 'Certificate Generator';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Certificate Generator' => null
];

include '../templates/header.php';
?>

<style>
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
                                <label class="form-label small fw-bold">Officiating Priest <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="override_priest" id="override_priest" placeholder="e.g. Rev. Fr. Heriberto C. Villas, O.M.I.">
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
    const modal = document.getElementById('generateModal');
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
            newRow.querySelector('input').focus();
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

    modal.addEventListener('show.bs.modal', function(e) {
        const button = e.relatedTarget;
        const certType = button.getAttribute('data-cert-type');
        const recordType = button.getAttribute('data-record-type') || certType;
        certTypeInput.value = certType;
        
        const isBaptism = (certType === 'baptism' || certType === 'baptism_certification');
        if (baptismFields) {
            baptismFields.style.display = isBaptism ? 'block' : 'none';
        }

        // Reset inputs
        if (isBaptism) {
            ['override_fullname', 'override_birth_place', 'override_birth_date', 'override_residence', 'override_father_name', 'override_father_birth_place', 'override_mother_name', 'override_mother_birth_place', 'override_baptism_date', 'override_priest'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            sponsorsList.innerHTML = '';
        }

        select.innerHTML = '<option>Loading...</option>';
        
        fetch('../api/get_records.php?type=' + recordType)
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
                } else {
                    select.innerHTML = '<option value="">No records available</option>';
                }
            })
            .catch(error => {
                console.error('Error loading records:', error);
                select.innerHTML = '<option value="">Error loading records</option>';
            });
    });

    select.addEventListener('change', function() {
        const certType = certTypeInput.value;
        const isBaptism = (certType === 'baptism' || certType === 'baptism_certification');
        if (!isBaptism || !this.value) return;

        fetch('../api/get_record_details.php?type=baptism&id=' + this.value)
            .then(res => res.json())
            .then(res => {
                if (res.success && res.data) {
                    const d = res.data;
                    document.getElementById('override_fullname').value = d.fullname || '';
                    document.getElementById('override_birth_place').value = d.birth_place || '';
                    document.getElementById('override_birth_date').value = d.birth_date || '';
                    document.getElementById('override_residence').value = d.residence || '';
                    document.getElementById('override_father_name').value = d.father_name || '';
                    document.getElementById('override_father_birth_place').value = d.father_birth_place || '';
                    document.getElementById('override_mother_name').value = d.mother_name || '';
                    document.getElementById('override_mother_birth_place').value = d.mother_birth_place || '';
                    document.getElementById('override_baptism_date').value = d.baptism_date || '';
                    document.getElementById('override_priest').value = d.priest || '';

                    sponsorsList.innerHTML = '';
                    const sps = (Array.isArray(d.sponsors) && d.sponsors.length >= 2) ? d.sponsors : ['', ''];
                    sps.forEach((sp, idx) => {
                        sponsorsList.appendChild(createSponsorRow(sp, idx));
                    });
                    updateModalSponsorRemoveButtons();
                }
            })
            .catch(err => console.error('Error fetching record details:', err));
    });

    form.addEventListener('submit', function(e) {
        const certType = certTypeInput.value;
        const isBaptism = (certType === 'baptism' || certType === 'baptism_certification');
        if (!isBaptism) return;

        const requiredFields = [
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

        let missing = [];
        requiredFields.forEach(f => {
            const el = document.getElementById(f.id);
            if (!el || !el.value.trim()) {
                missing.push(f.label);
                if (el) el.classList.add('is-invalid');
            } else {
                if (el) el.classList.remove('is-invalid');
            }
        });

        const sps = Array.from(sponsorsList.querySelectorAll('input[name="override_sponsors[]"]'))
            .map(i => i.value.trim())
            .filter(v => v.length > 0);

        if (sps.length < 2) {
            missing.push('At least 2 Sponsors (Ninong-Ninang)');
        }

        if (missing.length > 0) {
            e.preventDefault();
            alert('Cannot generate certificate. Every required field must be complete:\n\n• ' + missing.join('\n• '));
            return false;
        }
    });
});
</script>

<?php include '../templates/footer.php'; ?>
