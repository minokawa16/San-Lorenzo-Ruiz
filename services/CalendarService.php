<?php

final class CalendarService {
    public function __construct(private mysqli $db) {}

    public function syncReservation(int $reservationId, int $actorId): array {
        $stmt = $this->db->prepare("SELECT r.reservation_id,r.reservation_type,r.start_at,r.end_at,r.event_details,q.reference_number,GROUP_CONCAT(x.name ORDER BY x.name SEPARATOR ', ') resource_names FROM reservations r JOIN requests q ON q.request_id=r.request_id JOIN reservation_resources rr ON rr.reservation_id=r.reservation_id JOIN resources x ON x.resource_id=rr.resource_id WHERE r.reservation_id=? AND r.status='approved' GROUP BY r.reservation_id");
        $stmt->bind_param('i',$reservationId); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$r) return ['success'=>false,'message'=>'Only an approved reservation can be synchronized.'];
        $title = ucwords(str_replace('_',' ',$r['reservation_type'])) . ' — ' . $r['reference_number'];
        $date = substr($r['start_at'],0,10); $start = substr($r['start_at'],11,8); $end = substr($r['end_at'],11,8);
        $description = trim('Reservation '.$r['reference_number'].'. '.(string)$r['event_details']); $location=$r['resource_names'];$category='reservation';$status='active'; $source='reservation';
        $stmt=$this->db->prepare("INSERT INTO schedule_events (title,description,event_date,start_time,end_time,location,category,status,source_type,source_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),event_date=VALUES(event_date),start_time=VALUES(start_time),end_time=VALUES(end_time),location=VALUES(location),status='active',updated_at=NOW()");
        if(!$stmt)throw new RuntimeException('Unable to prepare calendar synchronization.');
        $stmt->bind_param('sssssssssii',$title,$description,$date,$start,$end,$location,$category,$status,$source,$reservationId,$actorId); if(!$stmt->execute()){ $message=$stmt->error;$stmt->close();throw new RuntimeException('Calendar synchronization failed: '.$message); } $stmt->close();
        return ['success'=>true,'message'=>'Calendar event synchronized.'];
    }

    public function cancelReservation(int $reservationId): void {
        $source='reservation'; $status='cancelled';
        $stmt=$this->db->prepare('UPDATE schedule_events SET status=? WHERE source_type=? AND source_id=?');
        if(!$stmt)throw new RuntimeException('Unable to prepare calendar cancellation.');
        $stmt->bind_param('ssi',$status,$source,$reservationId);if(!$stmt->execute()){$message=$stmt->error;$stmt->close();throw new RuntimeException('Calendar cancellation failed: '.$message);}$stmt->close();
    }
}
