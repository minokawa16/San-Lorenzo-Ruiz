<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/config.php';
require_once dirname(__DIR__).'/includes/helpers.php';
require_once dirname(__DIR__).'/services/NotificationService.php';
$limit=max(1,min(100,(int)($argv[1]??25)));$service=new NotificationService($conn);$sent=$failed=0;
$lock=$conn->query("SELECT GET_LOCK('tugon_notification_delivery_worker',0) acquired");
if(!$lock||(int)($lock->fetch_assoc()['acquired']??0)!==1){echo json_encode(['processed'=>0,'completed'=>0,'failed'=>0,'locked'=>true]).PHP_EOL;exit;}
try{
 $result=$conn->query("SELECT delivery_id FROM notification_deliveries WHERE channel IN('email','sms') AND status IN('pending','failed') AND attempt_count<5 AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY COALESCE(next_attempt_at,last_attempt_at,'1970-01-01'),delivery_id LIMIT $limit");
 while($row=$result->fetch_assoc()){try{$service->retry((int)$row['delivery_id']);$sent++;}catch(Throwable$e){$failed++;}}
 echo json_encode(['processed'=>$sent+$failed,'completed'=>$sent,'failed'=>$failed,'locked'=>false]).PHP_EOL;
}finally{$conn->query("SELECT RELEASE_LOCK('tugon_notification_delivery_worker')");}
