<?php
require_once __DIR__ . '/RequestService.php';
require_once __DIR__ . '/ResourceAvailabilityService.php';
require_once __DIR__ . '/CalendarService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../includes/audit.php';

final class ReservationService {
    private ResourceAvailabilityService $availability;
    private RequestService $requests;
    private CalendarService $calendar;

    public function __construct(private mysqli $db) {
        $this->availability = new ResourceAvailabilityService($db);
        $this->requests = new RequestService($db);
        $this->calendar = new CalendarService($db);
    }

    public function create(int $userId, array $input, string $idempotencyKey): array {
        $type = strtolower(trim((string)($input['reservation_type'] ?? '')));
        if (!in_array($type, ['wedding','baptism','confirmation','burial','church_venue'], true)) throw new InvalidArgumentException('Please select a valid reservation type.');
        [$startAt,$endAt,$duration,$setup,$cleanup,$details,$resourceIds] = $this->validateScheduleInput($input, true);
        $this->db->begin_transaction();
        try {
            $request = $this->requests->createInCurrentTransaction(['request_type'=>'reservation','description'=>$details],$userId,$idempotencyKey);
            if ($request['replayed']) {
                $stmt=$this->db->prepare('SELECT reservation_id,request_id,status FROM reservations WHERE request_id=?'); $stmt->bind_param('i',$request['request_id']);$stmt->execute();$existing=$stmt->get_result()->fetch_assoc();$stmt->close();
                if (!$existing) throw new RuntimeException('The previous reservation submission is incomplete.');
                $this->db->commit(); return $existing + ['replayed'=>true];
            }
            $this->availability->lockAvailableResources($resourceIds);
            $this->availability->assertAvailable($resourceIds,$startAt,$endAt,$setup,$cleanup);
            $requestId=(int)$request['request_id']; $eventDate=substr($startAt,0,10);$eventTime=substr($startAt,11,8);$status='pending';$timezone=ResourceAvailabilityService::TIMEZONE;
            $stmt=$this->db->prepare('INSERT INTO reservations (request_id,user_id,reservation_type,event_date,event_time,start_at,end_at,service_duration_minutes,setup_duration_minutes,cleanup_duration_minutes,timezone,event_details,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iisssssiiisss',$requestId,$userId,$type,$eventDate,$eventTime,$startAt,$endAt,$duration,$setup,$cleanup,$timezone,$details,$status); $stmt->execute();$reservationId=$stmt->insert_id;$stmt->close();
            $link=$this->db->prepare('INSERT INTO reservation_resources (reservation_id,resource_id) VALUES (?,?)'); foreach($resourceIds as $resourceId){$link->bind_param('ii',$reservationId,$resourceId);$link->execute();}$link->close();
            $reason='Reservation submitted';$changeType='initial_schedule';$history=$this->db->prepare('INSERT INTO reservation_schedule_history (reservation_id,new_start_at,new_end_at,changed_by,change_reason,change_type) VALUES (?,?,?,?,?,?)');$history->bind_param('ississ',$reservationId,$startAt,$endAt,$userId,$reason,$changeType);$history->execute();$history->close();
            $response=['request_id'=>$requestId,'reservation_id'=>$reservationId,'reference_number'=>$request['reference_number'],'status'=>$status];$json=json_encode($response);$operation='create_reservation';$update=$this->db->prepare('UPDATE request_idempotency_keys SET response_json=? WHERE user_id=? AND operation=? AND idempotency_key=?');$update->bind_param('siss',$json,$userId,$operation,$idempotencyKey);$update->execute();$update->close();
            if(!writeAuditLog($this->db,$userId,'CREATE_RESERVATION','reservations',$reservationId,null,json_encode($response)))throw new RuntimeException('Unable to record the reservation audit event.');
            $this->notify($userId,'reservation_created',[],'reservation',$reservationId,'reservation.view');
            $this->db->commit();
            return $response + ['replayed'=>false];
        } catch(Throwable $e){$this->db->rollback();if($e instanceof DomainException)$this->recordConflict($userId,null,$resourceIds,$startAt,$endAt,$e->getMessage());throw $e;}
    }

    public function changeStatus(int $reservationId,string $status,int $actorId,string $notes=''): array {
        if(!in_array($status,['pending','approved','rejected','cancelled'],true)) throw new InvalidArgumentException('Invalid reservation status.');
        if(in_array($status,['rejected','cancelled'],true) && mb_strlen(trim($notes))<5) throw new InvalidArgumentException('A meaningful reason is required.');
        $this->db->begin_transaction();
        try{
            $stmt=$this->db->prepare('SELECT * FROM reservations WHERE reservation_id=? FOR UPDATE');$stmt->bind_param('i',$reservationId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$r)throw new RuntimeException('Reservation not found.');
            $allowedTransitions=['pending'=>['pending','approved','rejected','cancelled'],'approved'=>['approved','cancelled'],'rejected'=>['rejected'],'cancelled'=>['cancelled']];
            if(!in_array($status,$allowedTransitions[$r['status']]??[],true))throw new DomainException('That reservation status transition is not allowed.');
            $resourceIds=$this->resourceIds($reservationId);$this->availability->lockAvailableResources($resourceIds);
            if($status==='approved')$this->availability->assertAvailable($resourceIds,$r['start_at'],$r['end_at'],(int)$r['setup_duration_minutes'],(int)$r['cleanup_duration_minutes'],$reservationId);
            $update=$this->db->prepare('UPDATE reservations SET status=?,admin_notes=? WHERE reservation_id=?');$update->bind_param('ssi',$status,$notes,$reservationId);$update->execute();$update->close();
            if($r['request_id'])$this->transitionLinkedRequest((int)$r['request_id'],$status,$actorId,$notes);
            if($status==='approved')$this->enqueueReminders($reservationId,$r['start_at']);
            if($status==='cancelled'){$reason=$notes;$changeType='cancellation';$h=$this->db->prepare('INSERT INTO reservation_schedule_history (reservation_id,previous_start_at,previous_end_at,new_start_at,new_end_at,changed_by,change_reason,change_type) VALUES (?,?,?,?,?,?,?,?)');$null=null;$h->bind_param('issssiss',$reservationId,$r['start_at'],$r['end_at'],$null,$null,$actorId,$reason,$changeType);$h->execute();$h->close();}
            if(!writeAuditLog($this->db,$actorId,'UPDATE_RESERVATION','reservations',$reservationId,json_encode(['status'=>$r['status']]),json_encode(['status'=>$status,'notes'=>$notes])))throw new RuntimeException('Unable to record the reservation audit event.');
            if($status==='approved')$calendar=$this->calendar->syncReservation($reservationId,$actorId);else{$this->calendar->cancelReservation($reservationId);$calendar=['success'=>true,'message'=>'Calendar event cancelled.'];}
            $notificationType=['approved'=>'reservation_approved','rejected'=>'reservation_rejected','cancelled'=>'reservation_cancelled'][$status]??'reservation_created';
            $this->notify((int)$r['user_id'],$notificationType,['reservation_date'=>date('F j, Y',strtotime($r['start_at']))],'reservation',$reservationId,'reservation.view');
            $this->db->commit();
            return ['status'=>$status,'calendar'=>$calendar];
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }

    public function proposeSchedule(int $reservationId,array $input,int $actorId,string $reason,?string $expiresAt=null): int {
        if(mb_strlen(trim($reason))<5)throw new InvalidArgumentException('A meaningful proposal reason is required.');
        [$startAt,$endAt,,$setup,$cleanup,,$resourceIds]=$this->validateScheduleInput($input,false);
        $this->db->begin_transaction();try{
            $stmt=$this->db->prepare("SELECT reservation_id,user_id,status FROM reservations WHERE reservation_id=? FOR UPDATE");$stmt->bind_param('i',$reservationId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$r||in_array($r['status'],['cancelled','rejected'],true))throw new DomainException('This reservation cannot receive a new schedule proposal.');
            $this->availability->lockAvailableResources($resourceIds);$this->availability->assertAvailable($resourceIds,$startAt,$endAt,$setup,$cleanup,$reservationId);
            $pending='pending';$stmt=$this->db->prepare('INSERT INTO schedule_proposals (reservation_id,proposed_start_at,proposed_end_at,reason,proposed_by,status,expires_at) VALUES (?,?,?,?,?,?,?)');$stmt->bind_param('isssiss',$reservationId,$startAt,$endAt,$reason,$actorId,$pending,$expiresAt);$stmt->execute();$proposalId=$stmt->insert_id;$stmt->close();
            $link=$this->db->prepare('INSERT INTO schedule_proposal_resources (proposal_id,resource_id) VALUES (?,?)');foreach($resourceIds as $id){$link->bind_param('ii',$proposalId,$id);$link->execute();}$link->close();
            $type='admin_proposal';$h=$this->db->prepare('INSERT INTO reservation_schedule_history (reservation_id,new_start_at,new_end_at,changed_by,change_reason,change_type) VALUES (?,?,?,?,?,?)');$h->bind_param('ississ',$reservationId,$startAt,$endAt,$actorId,$reason,$type);$h->execute();$h->close();
            if(!writeAuditLog($this->db,$actorId,'CREATE_SCHEDULE_PROPOSAL','schedule_proposals',$proposalId,null,json_encode(['start_at'=>$startAt,'end_at'=>$endAt,'resources'=>$resourceIds])))throw new RuntimeException('Unable to record the proposal audit event.');
            $this->notify((int)$r['user_id'],'schedule_proposal_created',[],'reservation',$reservationId,'reservation.view');
            $this->db->commit();return $proposalId;
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }

    public function respondToProposal(int $proposalId,int $userId,bool $accept): void {
        $this->db->begin_transaction();try{
            $stmt=$this->db->prepare('SELECT p.*,r.user_id,r.status reservation_status,r.start_at old_start,r.end_at old_end,r.setup_duration_minutes,r.cleanup_duration_minutes FROM schedule_proposals p JOIN reservations r ON r.reservation_id=p.reservation_id WHERE p.proposal_id=? FOR UPDATE');$stmt->bind_param('i',$proposalId);$stmt->execute();$p=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$p||(int)$p['user_id']!==$userId)throw new RuntimeException('Schedule proposal not found.');if($p['status']!=='pending')throw new DomainException('This proposal was already answered.');if($p['expires_at']&&$p['expires_at']<date('Y-m-d H:i:s'))throw new DomainException('This proposal has expired.');
            $status=$accept?'accepted':'rejected';$stmt=$this->db->prepare('UPDATE schedule_proposals SET status=?,responded_at=NOW() WHERE proposal_id=?');$stmt->bind_param('si',$status,$proposalId);$stmt->execute();$stmt->close();
            if($accept){$ids=[];$stmt=$this->db->prepare('SELECT resource_id FROM schedule_proposal_resources WHERE proposal_id=? ORDER BY resource_id');$stmt->bind_param('i',$proposalId);$stmt->execute();foreach($stmt->get_result() as $row)$ids[]=(int)$row['resource_id'];$stmt->close();$this->availability->lockAvailableResources($ids);$this->availability->assertAvailable($ids,$p['proposed_start_at'],$p['proposed_end_at'],(int)$p['setup_duration_minutes'],(int)$p['cleanup_duration_minutes'],(int)$p['reservation_id']);
                $date=substr($p['proposed_start_at'],0,10);$time=substr($p['proposed_start_at'],11,8);$duration=max(1,(int)((strtotime($p['proposed_end_at'])-strtotime($p['proposed_start_at']))/60));$u=$this->db->prepare('UPDATE reservations SET start_at=?,end_at=?,event_date=?,event_time=?,service_duration_minutes=? WHERE reservation_id=?');$u->bind_param('ssssii',$p['proposed_start_at'],$p['proposed_end_at'],$date,$time,$duration,$p['reservation_id']);$u->execute();$u->close();$d=$this->db->prepare('DELETE FROM reservation_resources WHERE reservation_id=?');$d->bind_param('i',$p['reservation_id']);$d->execute();$d->close();$l=$this->db->prepare('INSERT INTO reservation_resources VALUES (?,?,NOW())');foreach($ids as$id){$l->bind_param('ii',$p['reservation_id'],$id);$l->execute();}$l->close();
                $reason='Parishioner accepted schedule proposal #'.$proposalId;$type='parishioner_acceptance';$h=$this->db->prepare('INSERT INTO reservation_schedule_history (reservation_id,previous_start_at,previous_end_at,new_start_at,new_end_at,changed_by,change_reason,change_type) VALUES (?,?,?,?,?,?,?,?)');$h->bind_param('issssiss',$p['reservation_id'],$p['old_start'],$p['old_end'],$p['proposed_start_at'],$p['proposed_end_at'],$userId,$reason,$type);$h->execute();$h->close();}
            if($accept){$this->db->query('DELETE FROM reservation_notifications WHERE sent_at IS NULL AND reservation_id='.(int)$p['reservation_id']);$this->enqueueReminders((int)$p['reservation_id'],$p['proposed_start_at']);$this->db->query('UPDATE requests q JOIN reservations r ON r.request_id=q.request_id SET q.updated_at=NOW() WHERE r.reservation_id='.(int)$p['reservation_id']);}
            if(!writeAuditLog($this->db,$userId,$accept?'ACCEPT_SCHEDULE_PROPOSAL':'REJECT_SCHEDULE_PROPOSAL','schedule_proposals',$proposalId,json_encode(['status'=>'pending']),json_encode(['status'=>$status])))throw new RuntimeException('Unable to record the proposal response audit event.');
            if($accept&&$p['reservation_status']==='approved')$this->calendar->syncReservation((int)$p['reservation_id'],$userId);
            $this->notify((int)$p['proposed_by'],'schedule_proposal_response',['proposal_response'=>$accept?'accepted':'rejected'],'reservation',(int)$p['reservation_id'],'reservation.view');
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }

    private function validateScheduleInput(array $input,bool $mustBeFuture): array {
        $startAt=trim((string)($input['start_at']??''));$duration=(int)($input['service_duration_minutes']??60);$setup=(int)($input['setup_duration_minutes']??0);$cleanup=(int)($input['cleanup_duration_minutes']??0);if($duration<15||$duration>1440)throw new InvalidArgumentException('Service duration must be between 15 minutes and 24 hours.');
        $start=$this->availability->parseLocalDateTime($startAt);$endAt=trim((string)($input['end_at']??''));$end=$endAt===''?$start->modify('+'.$duration.' minutes'):$this->availability->parseLocalDateTime($endAt);$endAt=$end->format('Y-m-d H:i:s');$actual=(int)(($end->getTimestamp()-$start->getTimestamp())/60);if($actual!==$duration)$duration=$actual;if($duration<15||$duration>1440)throw new InvalidArgumentException('The reservation duration must be between 15 minutes and 24 hours.');
        if($mustBeFuture&&$start<=new DateTimeImmutable('now',new DateTimeZone(ResourceAvailabilityService::TIMEZONE)))throw new InvalidArgumentException('Please choose a future date and time.');
        $details=trim((string)($input['event_details']??''));if(mb_strlen($details)>5000)throw new InvalidArgumentException('Reservation details are too long.');$resourceIds=$this->availability->normalizeResourceIds((array)($input['resource_ids']??[]));return[$start->format('Y-m-d H:i:s'),$endAt,$duration,$setup,$cleanup,$details,$resourceIds];
    }

    private function resourceIds(int $reservationId): array {$ids=[];$stmt=$this->db->prepare('SELECT resource_id FROM reservation_resources WHERE reservation_id=? ORDER BY resource_id');$stmt->bind_param('i',$reservationId);$stmt->execute();foreach($stmt->get_result() as$row)$ids[]=(int)$row['resource_id'];$stmt->close();if(!$ids)throw new DomainException('The reservation has no assigned resource.');return$ids;}

    private function transitionLinkedRequest(int $requestId,string $reservationStatus,int $actorId,string $notes): void {
        $stmt=$this->db->prepare('SELECT status FROM requests WHERE request_id=?');$stmt->bind_param('i',$requestId);$stmt->execute();$current=RequestStateMachine::normalize((string)($stmt->get_result()->fetch_assoc()['status']??''));$stmt->close();
        if($reservationStatus==='approved'){if($current==='submitted'){$this->requests->transitionInCurrentTransaction($requestId,'requirements_review',$actorId,'Reservation schedule reviewed');$current='requirements_review';}if($current==='requirements_review')$this->requests->transitionInCurrentTransaction($requestId,'approved',$actorId,$notes?:'Reservation approved');}
        elseif($reservationStatus==='rejected'&&RequestStateMachine::canTransition($current,'rejected'))$this->requests->transitionInCurrentTransaction($requestId,'rejected',$actorId,$notes);
        elseif($reservationStatus==='cancelled'&&RequestStateMachine::canTransition($current,'cancelled'))$this->requests->transitionInCurrentTransaction($requestId,'cancelled',$actorId,$notes);
    }

    private function enqueueReminders(int $reservationId,string $startAt): void {
        $start=$this->availability->parseLocalDateTime($startAt);$now=new DateTimeImmutable('now',new DateTimeZone(ResourceAvailabilityService::TIMEZONE));
        $stmt=$this->db->prepare('INSERT IGNORE INTO reservation_notifications (reservation_id,notification_type,scheduled_for) VALUES (?,?,?)');
        $configured=getenv('RESERVATION_REMINDER_MINUTES')?:'1440,120';$minutesList=array_values(array_unique(array_filter(array_map('intval',explode(',',$configured)),fn($v)=>$v>0&&$v<=10080)));
        foreach($minutesList as$minutes){$type='reminder_'.$minutes.'m';$scheduled=$start->modify('-'.$minutes.' minutes');if($scheduled>$now){$value=$scheduled->format('Y-m-d H:i:s');$stmt->bind_param('iss',$reservationId,$type,$value);$stmt->execute();}}
        $stmt->close();
    }

    private function notify(int$userId,string$type,array$variables,string$entityType,int$entityId,string$actionKey):void{(new NotificationService($this->db))->create($userId,$type,$variables,$entityType,$entityId,$actionKey,$type.'|'.$entityId.'|'.microtime(true),true);}

    private function recordConflict(int $actorId,?int $reservationId,array $resourceIds,string $startAt,string $endAt,string $reason):void {
        $reason=mb_strimwidth(tugonRedactSensitive($reason),0,500,'');$correlation=tugonCorrelationId();$stmt=$this->db->prepare('INSERT INTO reservation_conflict_events(attempted_by,reservation_id,resource_id,requested_start,requested_end,reason,correlation_id) VALUES(?,?,?,?,?,?,?)');
        if(!$stmt)return;foreach($resourceIds as$resourceId){$stmt->bind_param('iiissss',$actorId,$reservationId,$resourceId,$startAt,$endAt,$reason,$correlation);$stmt->execute();}$stmt->close();
    }
}
