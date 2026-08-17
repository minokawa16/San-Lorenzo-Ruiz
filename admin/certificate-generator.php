<?php
/**
 * Certificate Generator Module - Builds sacramental certificate data for preview, print, and verification.
 */
session_start();
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('certificates.manage');

$error = '';
$success = '';

// Handle certificate generation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cert_type = $_POST['cert_type'] ?? '';
    $record_id = intval($_POST['record_id']);
    
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
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <?php include '../includes/back_button.php'; ?>

    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="mb-2"><i class="fas fa-file-pdf"></i> Certificate Generator</h1>
            <p class="text-muted">Generate and print certificates from parish records or temporary manual entry</p>
            <a href="manual-certificate-generator.php" class="btn btn-primary mt-2">
                <i class="fas fa-pen-to-square"></i> Manual Certificate Generator
            </a>
        </div>

    </div>

    <div class="row">
        <!-- Baptism -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #1a3a52; margin-bottom: 10px;">
                        <i class="fas fa-water"></i>
                    </div>
                    <h5 class="card-title">Baptism Certificates</h5>
                    <p class="text-muted"><strong><?php echo $baptism_count; ?></strong> Records</p>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="baptism">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>

        <!-- First Communion -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #28a745; margin-bottom: 10px;">
                        <i class="fas fa-bread-slice"></i>
                    </div>
                    <h5 class="card-title">First Communion Certificate</h5>
                    <p class="text-muted"><strong><?php echo $communion_count; ?></strong> Records</p>
                    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="communion">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>

        <!-- Confirmation -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #17a2b8; margin-bottom: 10px;">
                        <i class="fas fa-dove"></i>
                    </div>
                    <h5 class="card-title">Confirmation Certificates</h5>
                    <p class="text-muted"><strong><?php echo $confirmation_count; ?></strong> Records</p>
                    <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="confirmation">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>

    </div>

    <div class="row">
        <!-- Baptismal Certification -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #1a3a52; margin-bottom: 10px;">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <h5 class="card-title">Baptismal Certification</h5>
                    <p class="text-muted"><strong><?php echo $baptism_count; ?></strong> Baptism Records</p>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="baptism_certification" data-record-type="baptism">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>

        <!-- Confirmation Certification -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #17a2b8; margin-bottom: 10px;">
                        <i class="fas fa-file-circle-check"></i>
                    </div>
                    <h5 class="card-title">Confirmation Certification</h5>
                    <p class="text-muted"><strong><?php echo $confirmation_count; ?></strong> Confirmation Records</p>
                    <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="confirmation_certification" data-record-type="confirmation">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>

        <!-- First Communion Certification -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #28a745; margin-bottom: 10px;">
                        <i class="fas fa-file-lines"></i>
                    </div>
                    <h5 class="card-title">First Communion Certification</h5>
                    <p class="text-muted"><strong><?php echo $communion_count; ?></strong> First Communion Records</p>
                    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="first_communion_certification" data-record-type="communion">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>

        <!-- Marriage Certification -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #8b5a2b; margin-bottom: 10px;">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h5 class="card-title">Marriage Certification</h5>
                    <p class="text-muted"><strong><?php echo $marriage_count; ?></strong> Marriage Records</p>
                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="marriage_certification" data-record-type="marriage">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>

        <!-- Funeral Certification -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center">
                    <div style="font-size: 2.5rem; color: #6c757d; margin-bottom: 10px;">
                        <i class="fas fa-cross"></i>
                    </div>
                    <h5 class="card-title">Funeral Certification</h5>
                    <p class="text-muted"><strong><?php echo $funeral_count; ?></strong> Funeral Records</p>
                    <button class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#generateModal" data-cert-type="funeral_certification" data-record-type="funeral">
                        <i class="fas fa-file-pdf"></i> Generate
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Card -->
    <div class="card mt-4">
        <div class="card-body">
            <h5><i class="fas fa-info-circle"></i> How to Generate Certificates</h5>
            <ol>
                <li>First encode or update the parishioner's record in Sacramental Records</li>
                <li>Click on the certificate type card</li>
                <li>Select an existing manual record from the list</li>
                <li>Click "Generate Certificate"</li>
                <li>Review and print or export as PDF</li>
            </ol>
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
                <div class="modal-body">
                    <input type="hidden" name="cert_type" id="cert_type">
                    
                    <div class="mb-3">
                        <label for="record_id" class="form-label">Select Record</label>
                        <select class="form-select" id="record_id" name="record_id" required>
                            <option value="">-- Loading records --</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
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
