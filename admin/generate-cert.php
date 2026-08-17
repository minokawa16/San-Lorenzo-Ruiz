<?php
/**
 * Generate Certificate Page
 * Admin interface for generating sacramental certificates
 */

// Include centralized session management
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

// Require admin access
requireAdmin();
requirePermission('certificates.manage');

$cert_type = $_GET['type'] ?? '';
$record_id = intval($_GET['id'] ?? 0);

if ($record_id === 0 || empty($cert_type)) {
    $_SESSION['error'] = 'Invalid certificate request';
    header('Location: manage-certificates.php');
    exit;
}

// Fetch the record based on type
$record = null;

switch($cert_type) {
    case 'baptism':
    case 'baptism_certification':
        $sql = "SELECT * FROM baptism_records WHERE baptism_id = $record_id AND status = 'active'";
        break;
    case 'communion':
    case 'first_communion_certification':
        $sql = "SELECT * FROM first_communion_records WHERE communion_id = $record_id AND status = 'active'";
        break;
    case 'confirmation':
    case 'confirmation_certification':
        $sql = "SELECT * FROM confirmation_records WHERE confirmation_id = $record_id AND status = 'active'";
        break;
    case 'marriage':
    case 'marriage_certification':
        $sql = "SELECT * FROM marriage_records WHERE marriage_id = $record_id AND status = 'active'";
        break;
    case 'funeral_certification':
        $sql = "SELECT * FROM funeral_records WHERE funeral_id = $record_id AND status = 'active'";
        break;
    default:
        $_SESSION['error'] = 'Invalid record type';
        header('Location: certificate-generator.php');
        exit;
}

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $record = $result->fetch_assoc();
    unset($_SESSION['manual_certificate']);
    $_SESSION['certificate_data'] = $record;
    $_SESSION['cert_type'] = $cert_type;
    
    // Log this action
    createAuditLog($conn, $_SESSION['user_id'], 'GENERATE_CERTIFICATE', 'records', $record_id, 'Certificate generated for ' . $cert_type);
    
    header('Location: view-certificate.php');
    exit;
} else {
    $_SESSION['error'] = 'Record not found or inactive';
    header('Location: certificate-generator.php');
    exit;
}
?>
