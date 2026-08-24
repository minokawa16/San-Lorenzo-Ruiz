<?php
require_once __DIR__.'/NotificationService.php';

final class ReservationReminderService {
    public function __construct(private mysqli $db) {}

    public function sendDue(int $limit=100): array {
        $limit=max(1,min(500,$limit));$sent=0;$failed=0;
        for($i=0;$i<$limit;$i++){
            $this->db->begin_transaction();
            try{
                $result=$this->db->query("SELECT n.notification_id,n.notification_type,n.reservation_id,r.user_id,r.reservation_type,r.start_at FROM reservation_notifications n JOIN reservations r ON r.reservation_id=n.reservation_id WHERE n.sent_at IS NULL AND n.scheduled_for<=NOW() AND r.status='approved' ORDER BY n.scheduled_for,n.notification_id LIMIT 1 FOR UPDATE");
                if(!$result)throw new RuntimeException('Unable to claim due reservation reminders: '.$this->db->error);
                $row=$result->fetch_assoc();
                if(!$row){$this->db->commit();break;}
                $date=date('F j, Y',strtotime($row['start_at']));$time=date('g:i A',strtotime($row['start_at']));
                (new NotificationService($this->db))->create((int)$row['user_id'],'reservation_reminder',['reservation_date'=>$date,'reservation_time'=>$time],'reservation',(int)$row['reservation_id'],'reservation.view',$row['notification_type'],false);
                $stmt=$this->db->prepare('UPDATE reservation_notifications SET sent_at=NOW() WHERE notification_id=? AND sent_at IS NULL');$stmt->bind_param('i',$row['notification_id']);$stmt->execute();$stmt->close();
                $this->db->commit();$sent++;
            }catch(Throwable $e){$this->db->rollback();$failed++;break;}
        }
        return['sent'=>$sent,'failed'=>$failed];
    }
}
