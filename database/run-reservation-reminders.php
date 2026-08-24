<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/config.php';
require_once __DIR__.'/../services/ReservationReminderService.php';
date_default_timezone_set('Asia/Manila');
$result=(new ReservationReminderService($conn))->sendDue();
echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
