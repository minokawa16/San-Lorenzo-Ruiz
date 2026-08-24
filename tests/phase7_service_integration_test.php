<?php
require_once __DIR__.'/../database/config.php';
require_once __DIR__.'/../services/ReservationService.php';
$passed=0;$failed=0;function check7i($ok,$label){global$passed,$failed;echo($ok?'PASS':'FAIL').": $label\n";$ok?$passed++:$failed++;}
$user=(int)$conn->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$resource=(int)$conn->query("SELECT resource_id FROM resources WHERE status='available' AND deleted_at IS NULL ORDER BY resource_id LIMIT 1")->fetch_assoc()['resource_id'];
$key=hash('sha256','phase7-integration-'.random_bytes(16));$conflictKey=hash('sha256','phase7-conflict-'.random_bytes(16));$service=new ReservationService($conn);$reservationId=0;$requestId=0;$notificationFloor=(int)$conn->query('SELECT COALESCE(MAX(notification_id),0) id FROM notifications')->fetch_assoc()['id'];$auditFloor=(int)$conn->query('SELECT COALESCE(MAX(log_id),0) id FROM audit_log')->fetch_assoc()['id'];
try{
    $input=['reservation_type'=>'church_venue','start_at'=>'2098-08-21 09:00:00','service_duration_minutes'=>60,'setup_duration_minutes'=>15,'cleanup_duration_minutes'=>15,'event_details'=>'Phase 7 integration test','resource_ids'=>[$resource]];
    $created=$service->create($user,$input,$key);$reservationId=(int)$created['reservation_id'];$requestId=(int)$created['request_id'];
    check7i($reservationId>0&&$requestId>0&&!$created['replayed'],'reservation and unified request are created together');
    $replay=$service->create($user,$input,$key);check7i((int)$replay['reservation_id']===$reservationId&&$replay['replayed'],'duplicate submission replays the original reservation');
    $blocked=false;try{$service->create($user,$input,$conflictKey);}catch(DomainException$e){$blocked=true;}check7i($blocked,'a second request cannot take the occupied resource window');
    $service->changeStatus($reservationId,'approved',$user,'Approved by Phase 7 integration test');
    $service->changeStatus($reservationId,'approved',$user,'Approval replay test');
    $count=(int)$conn->query("SELECT COUNT(*) c FROM schedule_events WHERE source_type='reservation' AND source_id=$reservationId")->fetch_assoc()['c'];check7i($count===1,'calendar synchronization is idempotent');
    $requestStatus=$conn->query("SELECT status FROM requests WHERE request_id=$requestId")->fetch_assoc()['status'];check7i($requestStatus==='approved','reservation approval follows the unified request state machine');
    $reminders=(int)$conn->query("SELECT COUNT(*) c FROM reservation_notifications WHERE reservation_id=$reservationId")->fetch_assoc()['c'];check7i($reminders===2,'approval enqueues the 24-hour and 2-hour reminders once');
}catch(Throwable$e){echo'FAIL: unexpected integration error: '.$e->getMessage()."\n";$failed++;}
finally{
    if($reservationId){$conn->query("DELETE FROM notifications WHERE notification_id>$notificationFloor AND user_id=$user");$conn->query("DELETE FROM audit_log WHERE log_id>$auditFloor AND table_name='reservations' AND record_id=$reservationId");$conn->query("DELETE FROM schedule_events WHERE source_type='reservation' AND source_id=$reservationId");$conn->query("DELETE FROM reservation_notifications WHERE reservation_id=$reservationId");$conn->query("DELETE FROM reservation_schedule_history WHERE reservation_id=$reservationId");$conn->query("DELETE FROM reservation_resources WHERE reservation_id=$reservationId");$conn->query("DELETE FROM reservations WHERE reservation_id=$reservationId");}
    if($requestId){$conn->query("DELETE FROM request_status_history WHERE request_id=$requestId");$conn->query("DELETE FROM request_idempotency_keys WHERE request_id=$requestId");$conn->query("DELETE FROM requests WHERE request_id=$requestId");}
}
echo"Phase 7 integration: $passed passed, $failed failed.\n";exit($failed?1:0);
