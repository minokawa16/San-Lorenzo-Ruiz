<?php
/** Retired insecure guest AI route. Use the authenticated /api/ai-assistant.php endpoint. */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['success'=>false,'message'=>'This legacy endpoint has been retired.']);
?>
