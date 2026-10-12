<?php
/**
 * Records Lookup API - Retrieves sacramental records for administrator tools.
 */
header('Content-Type: application/json');
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';

requireAdmin();
requirePermission('certificates.manage');

$type = $_GET['type'] ?? '';
$q = trim($_GET['q'] ?? '');
$id = intval($_GET['id'] ?? 0);
$response = ['success' => false, 'records' => []];

try {
    $hasQuery = ($q !== '');
    $searchParam = '%' . $q . '%';
    $stmt = null;

    if ($id > 0) {
        switch($type) {
            case 'baptism':
                $stmt = $conn->prepare("SELECT baptism_id as id, fullname as name, baptism_date FROM baptism_records WHERE baptism_id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                break;
            case 'communion':
                $stmt = $conn->prepare("SELECT communion_id as id, fullname as name, communion_date FROM first_communion_records WHERE communion_id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                break;
            case 'confirmation':
                $stmt = $conn->prepare("SELECT confirmation_id as id, fullname as name, confirmation_date FROM confirmation_records WHERE confirmation_id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                break;
            case 'marriage':
                $stmt = $conn->prepare("SELECT marriage_id as id, CONCAT(husband_name, ' and ', wife_name) as name, wedding_date FROM marriage_records WHERE marriage_id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                break;
            case 'funeral':
                $stmt = $conn->prepare("SELECT funeral_id as id, deceased_name as name, date_of_burial FROM funeral_records WHERE funeral_id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                break;
            default:
                throw new Exception('Invalid record type');
        }
    } elseif ($hasQuery) {
        switch($type) {
            case 'baptism':
                $stmt = $conn->prepare("SELECT baptism_id as id, fullname as name, baptism_date FROM baptism_records WHERE status='active' AND fullname LIKE ? ORDER BY baptism_date DESC LIMIT 100");
                $stmt->bind_param('s', $searchParam);
                break;
            case 'communion':
                $stmt = $conn->prepare("SELECT communion_id as id, fullname as name, communion_date FROM first_communion_records WHERE status='active' AND fullname LIKE ? ORDER BY communion_date DESC LIMIT 100");
                $stmt->bind_param('s', $searchParam);
                break;
            case 'confirmation':
                $stmt = $conn->prepare("SELECT confirmation_id as id, fullname as name, confirmation_date FROM confirmation_records WHERE status='active' AND fullname LIKE ? ORDER BY confirmation_date DESC LIMIT 100");
                $stmt->bind_param('s', $searchParam);
                break;
            case 'marriage':
                $stmt = $conn->prepare("SELECT marriage_id as id, CONCAT(husband_name, ' and ', wife_name) as name, wedding_date FROM marriage_records WHERE status='active' AND (husband_name LIKE ? OR wife_name LIKE ? OR CONCAT(husband_name, ' and ', wife_name) LIKE ?) ORDER BY wedding_date DESC LIMIT 100");
                $stmt->bind_param('sss', $searchParam, $searchParam, $searchParam);
                break;
            case 'funeral':
                $stmt = $conn->prepare("SELECT funeral_id as id, deceased_name as name, date_of_burial FROM funeral_records WHERE status='active' AND deceased_name LIKE ? ORDER BY date_of_burial DESC LIMIT 100");
                $stmt->bind_param('s', $searchParam);
                break;
            default:
                throw new Exception('Invalid record type');
        }
    } else {
        switch($type) {
            case 'baptism':
                $stmt = $conn->prepare("SELECT baptism_id as id, fullname as name, baptism_date FROM baptism_records WHERE status='active' ORDER BY baptism_date DESC LIMIT 250");
                break;
            case 'communion':
                $stmt = $conn->prepare("SELECT communion_id as id, fullname as name, communion_date FROM first_communion_records WHERE status='active' ORDER BY communion_date DESC LIMIT 250");
                break;
            case 'confirmation':
                $stmt = $conn->prepare("SELECT confirmation_id as id, fullname as name, confirmation_date FROM confirmation_records WHERE status='active' ORDER BY confirmation_date DESC LIMIT 250");
                break;
            case 'marriage':
                $stmt = $conn->prepare("SELECT marriage_id as id, CONCAT(husband_name, ' and ', wife_name) as name, wedding_date FROM marriage_records WHERE status='active' ORDER BY wedding_date DESC LIMIT 250");
                break;
            case 'funeral':
                $stmt = $conn->prepare("SELECT funeral_id as id, deceased_name as name, date_of_burial FROM funeral_records WHERE status='active' ORDER BY date_of_burial DESC LIMIT 250");
                break;
            default:
                throw new Exception('Invalid record type');
        }
    }

    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $response['records'][] = $row;
        }
        $stmt->close();
        $response['success'] = true;
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
