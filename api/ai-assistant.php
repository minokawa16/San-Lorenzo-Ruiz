<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Logger.php';
require_once __DIR__ . '/../services/AiAssistantService.php';

function aiJson(array $payload, int $status = 200): void { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST'); aiJson(['success'=>false,'message'=>'Method not allowed.'],405);
}
if (!isLoggedIn() || empty($_SESSION['fully_authenticated'])) aiJson(['success'=>false,'message'=>'Please log in to use TUGON AI.'],401);
requireValidCsrfToken();
if (strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0])) !== 'application/json') aiJson(['success'=>false,'message'=>'Content-Type must be application/json.'],415);

$payload=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($payload)) aiJson(['success'=>false,'message'=>'Invalid JSON request.'],400);
$staff=hasPermission('ai.staff.use') || hasPermission('ai.admin.use');
if (!$staff && !hasPermission('ai.parishioner.use')) aiJson(['success'=>false,'message'=>'Your account is not authorized to use TUGON AI.'],403);

$caps=[
    'staff'=>$staff,
    'admin'=>hasPermission('ai.admin.use'),
    'records'=>hasPermission('ai.search.records'),
    'reports'=>hasPermission('ai.search.reports'),
    'feedback'=>hasPermission('ai.review.feedback'),
];

try {
    $service=new AiAssistantService($conn);
    if (($payload['action']??'') === 'feedback') {
        if (!$caps['feedback']) aiJson(['success'=>false,'message'=>'You are not authorized to review AI feedback.'],403);
        $service->saveFeedback((int)$_SESSION['user_id'],(string)($payload['response_reference']??''),(string)($payload['rating']??''),(string)($payload['comments']??''));
        aiJson(['success'=>true,'message'=>'Feedback saved.']);
    }
    $mode=in_array(($payload['mode']??'chat'),['chat','search','analytics'],true)?$payload['mode']:'chat';
    $conversation=[];
    foreach(array_slice((array)($payload['conversation']??[]),-6) as $turn){
        if(!is_array($turn))continue; $role=($turn['role']??'')==='assistant'?'assistant':'user';
        $conversation[]=['role'=>$role,'content'=>mb_strimwidth((string)($turn['content']??''),0,500,'')];
    }
    aiJson($service->respond((int)$_SESSION['user_id'],$caps,(string)($payload['message']??''),$mode,$conversation));
} catch (InvalidArgumentException|DomainException $e) {
    aiJson(['success'=>false,'message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    (new Logger())->error('AI request failed',['component'=>'ai','event'=>'ai.request.failed','exception'=>get_class($e),'message'=>$e->getMessage()]);
    aiJson(['success'=>false,'message'=>'The assistant is temporarily unavailable. Please contact parish staff if your concern is urgent.'],500);
}
