<?php
/**
 * Records Lookup API - Retrieves sacramental records for administrator tools.
 */
header('Content-Type: application/json');
session_start();
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('records.manage');

$type = $_GET['type'] ?? '';
$response = ['success' => false, 'records' => []];

try {
    switch($type) {
        case 'baptism':
            $sql = "SELECT baptism_id as id, fullname as name, baptism_date FROM baptism_records WHERE status='active' ORDER BY baptism_date DESC LIMIT 100";
            break;
        case 'communion':
            $sql = "SELECT communion_id as id, fullname as name, communion_date FROM first_communion_records WHERE status='active' ORDER BY communion_date DESC LIMIT 100";
            break;
        case 'confirmation':
            $sql = "SELECT confirmation_id as id, fullname as name, confirmation_date FROM confirmation_records WHERE status='active' ORDER BY confirmation_date DESC LIMIT 100";
            break;
        default:
            throw new Exception('Invalid record type');
    }
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $response['records'][] = $row;
        }
        $response['success'] = true;
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>
