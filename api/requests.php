<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../services/RequestService.php';

requireLogin();
$userId = (int) $_SESSION['user_id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'GET' ? $_GET : (json_decode((string) file_get_contents('php://input'), true) ?: $_POST);
$requestId = max(0, (int) ($input['request_id'] ?? $input['id'] ?? 0));

function requestApiFail(string $message, int $status = 422): void { http_response_code($status); echo json_encode(['success'=>false,'error'=>'REQUEST_VALIDATION_FAILED','message'=>$message]); exit; }
if ($requestId <= 0) requestApiFail('A valid request is required.');
$stmt = $conn->prepare('SELECT * FROM requests WHERE request_id=? AND deleted_at IS NULL LIMIT 1');
$stmt->bind_param('i', $requestId); $stmt->execute(); $request = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$request) requestApiFail('Request not found.', 404);
$staff = hasPermission('requests.manage');
if (!$staff && (int) $request['user_id'] !== $userId) requestApiFail('You are not authorized to access this request.', 403);

if ($method === 'GET') {
    $history = [];
    $h = $conn->prepare('SELECT previous_status,new_status,actor_user_id,reason,created_at FROM request_status_history WHERE request_id=? ORDER BY created_at ASC,history_id ASC'); $h->bind_param('i',$requestId);$h->execute();$result=$h->get_result();while($row=$result->fetch_assoc())$history[]=$row;$h->close();
    $messages = [];
    $m = $conn->prepare("SELECT message_id,sender_user_id,message,created_at,read_at FROM request_messages WHERE request_id=? AND visibility='public' ORDER BY created_at ASC,message_id ASC");$m->bind_param('i',$requestId);$m->execute();$result=$m->get_result();while($row=$result->fetch_assoc())$messages[]=$row;$m->close();
    echo json_encode(['success'=>true,'request'=>['request_id'=>(int)$request['request_id'],'reference_number'=>$request['reference_number'],'request_type'=>$request['request_type'],'status'=>RequestStateMachine::normalize($request['status']),'priority'=>$request['priority'],'due_date'=>$request['due_date'],'next_action'=>RequestStateMachine::nextAction($request['status'])],'history'=>$history,'messages'=>$messages]); exit;
}
requireValidCsrfToken();
$action = strtolower(trim((string) ($input['action'] ?? '')));
try {
    if ($action === 'transition') {
        if (!$staff) {
            if ((int)$request['user_id'] !== $userId || !in_array(RequestStateMachine::normalize($request['status']), ['draft','submitted','needs_information','payment_required','scheduled'], true) || RequestStateMachine::normalize((string)$input['status']) !== 'cancelled') requestApiFail('This transition is not permitted.', 403);
        }
        $result = (new RequestService($conn))->transition($requestId, (string)($input['status'] ?? ''), $userId, (string)($input['reason'] ?? ''));
        echo json_encode(['success'=>true,'result'=>$result]); exit;
    }
    if ($action === 'message') {
        $message = trim((string)($input['message'] ?? '')); if ($message === '' || strlen($message)>5000) requestApiFail('Message is required and must be 5000 characters or fewer.');
        $visibility = $staff && ($input['visibility'] ?? 'public') === 'internal' ? 'internal' : 'public';
        $stmt=$conn->prepare('INSERT INTO request_messages (request_id,sender_user_id,message,visibility) VALUES (?,?,?,?)');$stmt->bind_param('iiss',$requestId,$userId,$message,$visibility);$stmt->execute();$id=$stmt->insert_id;$stmt->close();
        echo json_encode(['success'=>true,'message_id'=>$id]); exit;
    }
    if ($action === 'internal_note' && $staff) {
        $note=trim((string)($input['note'] ?? '')); if ($note==='' || strlen($note)>5000) requestApiFail('Internal note is required.');
        $stmt=$conn->prepare('INSERT INTO request_internal_notes (request_id,author_user_id,note) VALUES (?,?,?)');$stmt->bind_param('iis',$requestId,$userId,$note);$stmt->execute();$id=$stmt->insert_id;$stmt->close();echo json_encode(['success'=>true,'note_id'=>$id]);exit;
    }
    requestApiFail('Unsupported request action.', 400);
} catch (Throwable $e) { safeErrorResponse('The request operation could not be completed.', 500); }
