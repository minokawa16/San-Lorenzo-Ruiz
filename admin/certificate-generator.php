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
        $sql = "SELECT * FROM baptism_records WHERE baptism_id = $record_id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $record = $result->fetch_assoc();
            unset($_SESSION['manual_certificate']);
            $_SESSION['certificate_data'] = $record;
            $_SESSION['cert_type'] = $cert_type;
            header('Location: view-certificate.php');
            exit;
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
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Generate Certificate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?php echo csrfInput(); ?>
                <div class="modal-body">
                    <input type="hidden" name="cert_type" id="cert_type">
                    <div class="mb-3">
                        <label for="record_id" class="form-label" style="font-weight: 600; color: #1e293b;">Select Record</label>
                        <select class="form-select" id="record_id" name="record_id" required>
                            <option value="">-- Loading records --</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-gold">
                        <i class="fas fa-file-pdf"></i> Generate Certificate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('generateModal').addEventListener('show.bs.modal', function(e) {
    const button = e.relatedTarget;
    const certType = button.getAttribute('data-cert-type');
    const recordType = button.getAttribute('data-record-type') || certType;
    document.getElementById('cert_type').value = certType;
    
    // Load records for this type
    const select = document.getElementById('record_id');
    select.innerHTML = '<option>Loading...</option>';
    
    // Fetch records
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
                select.innerHTML = '<option>No records available</option>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            select.innerHTML = '<option>Error loading records</option>';
        });
});
</script>

<?php include '../templates/footer.php'; ?>
