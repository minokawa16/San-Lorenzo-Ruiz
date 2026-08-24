<?php
require_once __DIR__.'/../database/config.php';
require_once __DIR__.'/../services/ResourceAvailabilityService.php';

$passed=0;$failed=0;
function check7(bool $condition,string $label):void{global$passed,$failed;if($condition){echo"PASS: $label\n";$passed++;}else{echo"FAIL: $label\n";$failed++;}}
function throws7(callable $callback,string $class=DomainException::class):bool{try{$callback();return false;}catch(Throwable$e){return is_a($e,$class);}}

$user=(int)($conn->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetch_assoc()['id']??0);
$resourceRows=$conn->query("SELECT resource_id FROM resources WHERE status='available' AND deleted_at IS NULL ORDER BY resource_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if($user<=0||count($resourceRows)<2){fwrite(STDERR,"Phase 7 tests require one user and two available resources.\n");exit(2);}
$primary=(int)$resourceRows[0]['resource_id'];$secondary=(int)$resourceRows[1]['resource_id'];

$conn->begin_transaction();
try{
    $type='church_venue';$date='2099-06-15';$time='10:00:00';$start='2099-06-15 10:00:00';$end='2099-06-15 11:00:00';$status='pending';
    $stmt=$conn->prepare('INSERT INTO reservations (user_id,reservation_type,event_date,event_time,start_at,end_at,service_duration_minutes,setup_duration_minutes,cleanup_duration_minutes,event_details,status) VALUES (?,?,?,?,?,?,60,15,15,?,?)');$details='Phase 7 transactional test';$stmt->bind_param('isssssss',$user,$type,$date,$time,$start,$end,$details,$status);$stmt->execute();$reservationId=$stmt->insert_id;$stmt->close();
    $stmt=$conn->prepare('INSERT INTO reservation_resources (reservation_id,resource_id) VALUES (?,?)');$stmt->bind_param('ii',$reservationId,$primary);$stmt->execute();$stmt->close();
    $availability=new ResourceAvailabilityService($conn);
    check7(throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 10:00:00','2099-06-15 11:00:00')),'exact overlap is rejected');
    check7(throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 09:30:00','2099-06-15 10:15:00')),'partial overlap is rejected');
    check7(throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 10:20:00','2099-06-15 10:40:00')),'contained overlap is rejected');
    check7(throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 08:30:00','2099-06-15 09:50:00')),'setup occupancy overlap is rejected');
    check7(!throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 08:00:00','2099-06-15 09:45:00')),'slot adjacent to setup boundary is allowed');
    check7(!throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 11:15:00','2099-06-15 12:00:00')),'slot adjacent to cleanup boundary is allowed');
    check7(!throws7(fn()=>$availability->assertAvailable([$secondary],'2099-06-15 10:00:00','2099-06-15 11:00:00')),'same time on a different resource is allowed');
    $conn->query("UPDATE reservations SET status='cancelled' WHERE reservation_id=$reservationId");
    check7(!throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 10:00:00','2099-06-15 11:00:00')),'cancelled reservations do not block availability');
    $conn->query("UPDATE reservations SET status='rejected' WHERE reservation_id=$reservationId");
    check7(!throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-15 10:00:00','2099-06-15 11:00:00')),'rejected reservations do not block availability');
    $reason='Phase 7 blackout';$stmt=$conn->prepare('INSERT INTO resource_unavailability (resource_id,start_at,end_at,reason) VALUES (?,?,?,?)');$blackoutStart='2099-06-16 08:00:00';$blackoutEnd='2099-06-16 17:00:00';$stmt->bind_param('isss',$primary,$blackoutStart,$blackoutEnd,$reason);$stmt->execute();$stmt->close();
    check7(throws7(fn()=>$availability->assertAvailable([$primary],'2099-06-16 09:00:00','2099-06-16 10:00:00')),'one-time resource blackout is enforced');
    check7(throws7(fn()=>$availability->assertAvailable([$primary,$secondary],'2099-06-16 00:00:00','2099-06-17 00:00:00')),'full-day blackout blocks a multiple-resource request');
    $weeklyTarget='2099-06-17';$rule='weekly:'.date('w',strtotime($weeklyTarget));$weeklyStart='2099-01-01 12:00:00';$weeklyEnd='2099-01-01 13:00:00';$stmt=$conn->prepare('INSERT INTO resource_unavailability (resource_id,start_at,end_at,reason,recurrence_rule) VALUES (?,?,?,?,?)');$stmt->bind_param('issss',$secondary,$weeklyStart,$weeklyEnd,$reason,$rule);$stmt->execute();$stmt->close();
    check7(throws7(fn()=>$availability->assertAvailable([$secondary],'2099-06-17 12:15:00','2099-06-17 12:45:00')),'weekly recurring blackout is enforced');
}finally{$conn->rollback();}

// Actual two-connection contention test: the second transaction must be unable
// to lock a resource while the first transaction holds its FOR UPDATE row lock.
$conn2=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME,DB_PORT);$conn2->set_charset('utf8mb4');
$conn->begin_transaction();$conn2->query('SET SESSION innodb_lock_wait_timeout=1');$conn2->begin_transaction();
$lock1=$conn->query("SELECT resource_id FROM resources WHERE resource_id=$primary FOR UPDATE");
$started=microtime(true);$lock2=@$conn2->query("SELECT resource_id FROM resources WHERE resource_id=$primary FOR UPDATE");$elapsed=microtime(true)-$started;
check7($lock1!==false&&$lock2===false&&$elapsed>=0.8,'concurrent creators serialize on the resource row lock');
$conn2->rollback();$conn->rollback();$conn2->close();

echo"Phase 7: $passed passed, $failed failed.\n";
exit($failed===0?0:1);
