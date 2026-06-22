<?php
/**
 * Records Management API - Creates, updates, and deletes sacramental records through admin requests.
 */
header('Content-Type: application/json');
session_start();
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('records.manage');

$record_type = $_POST['record_type'] ?? '';
$action = $_POST['action'] ?? 'add';

$response = [
    'success' => false,
    'message' => '',
    'id' => null
];

try {
    if ($action == 'add') {
        switch($record_type) {
            case 'baptism':
                $fullname = $conn->real_escape_string(sanitize($_POST['fullname']));
                $birth_date = $conn->real_escape_string($_POST['birth_date']);
                $baptism_date = $conn->real_escape_string($_POST['baptism_date']);
                $parents = $conn->real_escape_string(sanitize($_POST['parents']));
                $godparents = $conn->real_escape_string(sanitize($_POST['godparents']));
                $priest = $conn->real_escape_string(sanitize($_POST['priest']));
                
                $sql = "INSERT INTO baptism_records (fullname, birth_date, baptism_date, parents, godparents, priest, status)
                        VALUES ('$fullname', '$birth_date', '$baptism_date', '$parents', '$godparents', '$priest', 'active')";
                break;
                
            case 'communion':
                $fullname = $conn->real_escape_string(sanitize($_POST['fullname']));
                $birth_date = $conn->real_escape_string($_POST['birth_date']);
                $communion_date = $conn->real_escape_string($_POST['communion_date']);
                $parents = $conn->real_escape_string(sanitize($_POST['parents']));
                $priest = $conn->real_escape_string(sanitize($_POST['priest']));
                
                $sql = "INSERT INTO first_communion_records (fullname, birth_date, communion_date, parents, priest, status)
                        VALUES ('$fullname', '$birth_date', '$communion_date', '$parents', '$priest', 'active')";
                break;
                
            case 'confirmation':
                $fullname = $conn->real_escape_string(sanitize($_POST['fullname']));
                $birth_date = $conn->real_escape_string($_POST['birth_date']);
                $confirmation_date = $conn->real_escape_string($_POST['confirmation_date']);
                $confirmation_name = $conn->real_escape_string(sanitize($_POST['confirmation_name']));
                $sponsor = $conn->real_escape_string(sanitize($_POST['sponsor']));
                $bishop_priest = $conn->real_escape_string(sanitize($_POST['bishop_priest']));
                
                $sql = "INSERT INTO confirmation_records (fullname, birth_date, confirmation_date, confirmation_name, sponsor, bishop_priest, status)
                        VALUES ('$fullname', '$birth_date', '$confirmation_date', '$confirmation_name', '$sponsor', '$bishop_priest', 'active')";
                break;
                
            case 'marriage':
                $husband_name = $conn->real_escape_string(sanitize($_POST['husband_name']));
                $wife_name = $conn->real_escape_string(sanitize($_POST['wife_name']));
                $wedding_date = $conn->real_escape_string($_POST['wedding_date']);
                $sponsors = $conn->real_escape_string(sanitize($_POST['sponsors']));
                $officiating_priest = $conn->real_escape_string(sanitize($_POST['officiating_priest']));
                
                $sql = "INSERT INTO marriage_records (husband_name, wife_name, wedding_date, sponsors, officiating_priest, status)
                        VALUES ('$husband_name', '$wife_name', '$wedding_date', '$sponsors', '$officiating_priest', 'active')";
                break;
                
            default:
                throw new Exception('Invalid record type');
        }
        
        if ($conn->query($sql)) {
            $response['success'] = true;
            $response['id'] = $conn->insert_id;
            $response['message'] = 'Record added successfully';
            createAuditLog($conn, $_SESSION['user_id'], 'ADD_RECORD', $record_type . '_records', $conn->insert_id);
        } else {
            throw new Exception('Database error: ' . $conn->error);
        }
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
