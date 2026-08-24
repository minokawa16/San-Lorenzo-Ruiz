<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
requireAdmin();
requirePermission('records.manage');
requireValidCsrfToken();

$type = trim((string) ($_POST['record_type'] ?? ''));
$action = trim((string) ($_POST['action'] ?? 'add'));
$definitions = [
    'baptism' => ['table' => 'baptism_records', 'fields' => ['fullname','birth_date','baptism_date','parents','godparents','priest']],
    'communion' => ['table' => 'first_communion_records', 'fields' => ['fullname','birth_date','communion_date','parents','priest']],
    'confirmation' => ['table' => 'confirmation_records', 'fields' => ['fullname','birth_date','confirmation_date','confirmation_name','sponsor','bishop_priest']],
    'marriage' => ['table' => 'marriage_records', 'fields' => ['husband_name','wife_name','wedding_date','sponsors','officiating_priest']],
];
if ($action !== 'add' || !isset($definitions[$type])) {
    http_response_code(422); echo json_encode(['success'=>false,'error'=>'INVALID_REQUEST','message'=>'The record request is invalid.']); exit;
}
$definition = $definitions[$type]; $values = [];
foreach ($definition['fields'] as $field) {
    $value = trim((string) ($_POST[$field] ?? ''));
    if ($value === '' || strlen($value) > 255) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'VALIDATION_FAILED','message'=>'Complete all record fields using valid values.']); exit; }
    if (str_ends_with($field, '_date') || $field === 'birth_date' || $field === 'wedding_date') {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'VALIDATION_FAILED','message'=>'Dates must use YYYY-MM-DD format.']); exit; }
    }
    $values[] = $value;
}
$columns = implode(', ', $definition['fields']) . ', status';
$sql = "INSERT INTO {$definition['table']} ({$columns}) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ", 'active')";
$stmt = $conn->prepare($sql);
if (!$stmt) safeErrorResponse('The record could not be saved.', 500);
$stmt->bind_param(str_repeat('s', count($values)), ...$values);
if (!$stmt->execute()) { $stmt->close(); safeErrorResponse('The record could not be saved.', 500); }
$id = $stmt->insert_id; $stmt->close();
createAuditLog($conn, (int) $_SESSION['user_id'], 'ADD_RECORD', $definition['table'], $id);
echo json_encode(['success'=>true,'id'=>$id,'message'=>'Record added successfully.']);
