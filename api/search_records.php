<?php
/**
 * Sacramental Records Search API - Searches parish records for administrator workflows.
 */
header('Content-Type: application/json');
session_start();
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('records.manage');

$action = $_GET['action'] ?? '';
$search_type = $_GET['type'] ?? 'baptism';
$search_query = trim($_GET['q'] ?? '');
$page = intval($_GET['page'] ?? 1);
$limit = 20;

$response = [
    'success' => false,
    'data' => [],
    'message' => ''
];

if (empty($search_query)) {
    $response['message'] = 'Search query cannot be empty';
    echo json_encode($response);
    exit;
}

$search_query = $conn->real_escape_string($search_query);
$offset = ($page - 1) * $limit;

try {
    switch($search_type) {
        case 'baptism':
            $sql = "SELECT * FROM baptism_records 
                   WHERE status = 'active' 
                   AND (fullname LIKE '%$search_query%' 
                        OR registry_no LIKE '%$search_query%'
                        OR parents LIKE '%$search_query%'
                        OR parent_address LIKE '%$search_query%'
                        OR godparents LIKE '%$search_query%'
                        OR parish_address LIKE '%$search_query%'
                        OR priest LIKE '%$search_query%'
                        OR birth_place LIKE '%$search_query%')
                   ORDER BY baptism_date DESC
                   LIMIT $offset, $limit";
            break;
            
        case 'communion':
            $sql = "SELECT * FROM first_communion_records 
                   WHERE status = 'active' 
                   AND (fullname LIKE '%$search_query%' 
                        OR parents LIKE '%$search_query%')
                   ORDER BY communion_date DESC
                   LIMIT $offset, $limit";
            break;
            
        case 'confirmation':
            $sql = "SELECT * FROM confirmation_records 
                   WHERE status = 'active' 
                   AND (fullname LIKE '%$search_query%' 
                        OR sponsor LIKE '%$search_query%')
                   ORDER BY confirmation_date DESC
                   LIMIT $offset, $limit";
            break;
            
        case 'marriage':
            $sql = "SELECT * FROM marriage_records 
                   WHERE status = 'active' 
                   AND (registry_no LIKE '%$search_query%'
                        OR husband_name LIKE '%$search_query%' 
                        OR wife_name LIKE '%$search_query%'
                        OR husband_parents LIKE '%$search_query%'
                        OR wife_parents LIKE '%$search_query%'
                        OR sponsors LIKE '%$search_query%'
                        OR witnesses_residence LIKE '%$search_query%'
                        OR officiating_priest LIKE '%$search_query%'
                        OR husband_birth_origin LIKE '%$search_query%'
                        OR wife_birth_origin LIKE '%$search_query%'
                        OR husband_residence LIKE '%$search_query%'
                        OR wife_residence LIKE '%$search_query%')
                   ORDER BY wedding_date DESC
                   LIMIT $offset, $limit";
            break;
            
        default:
            throw new Exception('Invalid search type');
    }
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $response['success'] = true;
    $response['data'] = [];
    
    while ($row = $result->fetch_assoc()) {
        $response['data'][] = $row;
    }
    
    $response['message'] = 'Found ' . count($response['data']) . ' results';
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>
