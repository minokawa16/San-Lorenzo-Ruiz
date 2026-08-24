<?php

final class ReportService
{
    public const TYPES=['turnaround','pending_overdue','rejections','reservations','certificates','notifications'];
    public function __construct(private mysqli $db) {}

    public function run(string $type,array $filters,int $page=1,int $perPage=50):array
    {
        if(!in_array($type,self::TYPES,true))$type='turnaround';$page=max(1,$page);$perPage=max(10,min(100,$perPage));$offset=($page-1)*$perPage;
        [$select,$from,$base,$columns]=$this->definition($type);
        [$where,$types,$values]=$this->filters($type,$filters,$base);
        $count=$this->db->prepare("SELECT COUNT(*) c $from $where");if($types!=='')$count->bind_param($types,...$values);$count->execute();$total=(int)($count->get_result()->fetch_assoc()['c']??0);$count->close();
        $stmt=$this->db->prepare("SELECT $select $from $where ORDER BY report_date DESC LIMIT ? OFFSET ?");$bindTypes=$types.'ii';$bind=[...$values,$perPage,$offset];$stmt->bind_param($bindTypes,...$bind);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        return ['type'=>$type,'columns'=>$columns,'rows'=>$rows,'summary'=>$this->summary($type,$filters),'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage)),'truncated'=>$total>10000,'limit'=>10000,'filters'=>$filters];
    }

    public function export(string $type,array $filters,int $limit=10000):array
    {
        $result=$this->run($type,$filters,1,min(100,$limit));
        if($result['total']<=100)return $result;
        [$select,$from,$base,$columns]=$this->definition($type);[$where,$types,$values]=$this->filters($type,$filters,$base);$limit=max(1,min(10000,$limit));
        $stmt=$this->db->prepare("SELECT $select $from $where ORDER BY report_date DESC LIMIT ?");$bindTypes=$types.'i';$bind=[...$values,$limit];$stmt->bind_param($bindTypes,...$bind);$stmt->execute();$result['rows']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $result;
    }

    private function definition(string $type):array
    {
        return match($type){
            'pending_overdue'=>["r.request_id,r.reference_number,r.request_type,r.status,r.priority,r.due_date,r.date_requested report_date,CASE WHEN r.due_date<CURRENT_DATE THEN 'Overdue' WHEN r.due_date<=DATE_ADD(CURRENT_DATE,INTERVAL 3 DAY) THEN 'Due soon' ELSE 'Pending' END timing",'FROM requests r',"r.deleted_at IS NULL AND r.status NOT IN ('completed','rejected','cancelled')",['reference_number'=>'Reference','request_type'=>'Type','status'=>'Status','priority'=>'Priority','due_date'=>'Due date','timing'=>'Timing','report_date'=>'Submitted']],
            'rejections'=>["r.request_id,r.reference_number,r.request_type,h.reason rejection_reason,h.created_at report_date,(SELECT COUNT(*) FROM request_status_history a WHERE a.request_id=r.request_id AND a.new_status='submitted') submission_attempts",'FROM request_status_history h JOIN requests r ON r.request_id=h.request_id',"h.new_status='rejected'",['reference_number'=>'Reference','request_type'=>'Type','rejection_reason'=>'Reason','submission_attempts'=>'Attempts','report_date'=>'Rejected']],
            'reservations'=>["rv.reservation_id,rv.reservation_type,rv.status,rv.event_date,rv.event_time,COALESCE(res.name,'Unassigned') resource_name,rv.created_at report_date",'FROM reservations rv LEFT JOIN reservation_resources rr ON rr.reservation_id=rv.reservation_id LEFT JOIN resources res ON res.resource_id=rr.resource_id','1=1',['reservation_type'=>'Type','status'=>'Status','event_date'=>'Event date','event_time'=>'Time','resource_name'=>'Resource','report_date'=>'Submitted']],
            'certificates'=>["c.certificate_number,c.certificate_type,c.status,c.issued_at,c.released_at,c.revoked_at,c.updated_at report_date",'FROM certificate_issuances c','1=1',['certificate_number'=>'Certificate','certificate_type'=>'Type','status'=>'Status','issued_at'=>'Issued','released_at'=>'Released','revoked_at'=>'Revoked','report_date'=>'Updated']],
            'notifications'=>["nd.delivery_id,nd.channel,nd.status,nd.attempt_count,n.notification_type,nd.sent_at,nd.failure_reason,COALESCE(nd.last_attempt_at,n.created_at) report_date",'FROM notification_deliveries nd JOIN notifications n ON n.notification_id=nd.notification_id','1=1',['channel'=>'Channel','status'=>'Status','notification_type'=>'Type','attempt_count'=>'Attempts','sent_at'=>'Sent','failure_reason'=>'Failure','report_date'=>'Last activity']],
            default=>["r.reference_number,r.request_type,r.status,r.date_requested report_date,(SELECT MIN(h1.created_at) FROM request_status_history h1 WHERE h1.request_id=r.request_id AND h1.new_status IN ('processing','requirements_review')) processing_started,(SELECT MAX(h2.created_at) FROM request_status_history h2 WHERE h2.request_id=r.request_id AND h2.new_status='completed') completed_at,TIMESTAMPDIFF(HOUR,r.date_requested,(SELECT MAX(h3.created_at) FROM request_status_history h3 WHERE h3.request_id=r.request_id AND h3.new_status='completed')) turnaround_hours,COALESCE(u.fullname,'Unassigned') responsible_staff",'FROM requests r LEFT JOIN users u ON u.id=r.assigned_to',"r.deleted_at IS NULL",['reference_number'=>'Reference','request_type'=>'Type','status'=>'Status','report_date'=>'Submitted','processing_started'=>'Processing start','completed_at'=>'Completed','turnaround_hours'=>'Hours','responsible_staff'=>'Staff']]
        };
    }

    private function filters(string $type,array $f,string $base):array
    {
        $clauses=[$base];$types='';$values=[];$dateColumn=match($type){'rejections'=>'h.created_at','reservations'=>'rv.created_at','certificates'=>'c.updated_at','notifications'=>'COALESCE(nd.last_attempt_at,n.created_at)',default=>'r.date_requested'};
        if(($f['from']??'')!==''){$clauses[]="$dateColumn>=?";$types.='s';$values[]=$f['from'].' 00:00:00';}
        if(($f['to']??'')!==''){$clauses[]="$dateColumn<=?";$types.='s';$values[]=$f['to'].' 23:59:59';}
        $alias=match($type){'reservations'=>'rv','certificates'=>'c','notifications'=>'nd','rejections'=>'r',default=>'r'};
        if(($f['status']??'')!==''){$clauses[]="$alias.status=?";$types.='s';$values[]=$f['status'];}
        if(($f['type']??'')!==''){$col=match($type){'reservations'=>'rv.reservation_type','certificates'=>'c.certificate_type','notifications'=>'n.notification_type',default=>'r.request_type'};$clauses[]="$col=?";$types.='s';$values[]=$f['type'];}
        return ['WHERE '.implode(' AND ',$clauses),$types,$values];
    }

    private function summary(string $type,array $f):array
    {
        $range='';$types='';$values=[];$column=match($type){'reservations'=>'created_at','certificates'=>'updated_at','notifications'=>'COALESCE(nd.last_attempt_at,n.created_at)',default=>null};
        if($column&&($f['from']??'')!==''){$range.=" AND $column>=?";$types.='s';$values[]=$f['from'].' 00:00:00';}if($column&&($f['to']??'')!==''){$range.=" AND $column<=?";$types.='s';$values[]=$f['to'].' 23:59:59';}
        if($type==='reservations'){$sql="SELECT SUM(status IN('pending','approved')) active,SUM(status='cancelled') cancelled,SUM(status='rejected') rejected,COUNT(*) total FROM reservations WHERE 1=1$range";}
        elseif($type==='certificates'){$sql="SELECT SUM(status='issued') issued,SUM(status='released') released,SUM(status='revoked') revoked,SUM(status='reissued') reissued FROM certificate_issuances WHERE 1=1$range";}
        elseif($type==='notifications'){$sql="SELECT SUM(nd.status='sent') sent,SUM(nd.status='failed') failed,SUM(nd.status='pending') pending,SUM(nd.attempt_count>1) retried FROM notification_deliveries nd JOIN notifications n ON n.notification_id=nd.notification_id WHERE 1=1$range";}
        else return [];
        $stmt=$this->db->prepare($sql);if($types!=='')$stmt->bind_param($types,...$values);$stmt->execute();$summary=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();
        if($type==='reservations'){$conflictSql='SELECT COUNT(*) c FROM reservation_conflict_events WHERE 1=1';$conflictTypes='';$conflictValues=[];if(($f['from']??'')!==''){$conflictSql.=' AND created_at>=?';$conflictTypes.='s';$conflictValues[]=$f['from'].' 00:00:00';}if(($f['to']??'')!==''){$conflictSql.=' AND created_at<=?';$conflictTypes.='s';$conflictValues[]=$f['to'].' 23:59:59';}$s=$this->db->prepare($conflictSql);if($conflictTypes!=='')$s->bind_param($conflictTypes,...$conflictValues);$s->execute();$summary['conflicts_prevented']=(int)($s->get_result()->fetch_assoc()['c']??0);$s->close();$peak=$this->db->query("SELECT event_date,COUNT(*) c FROM reservations WHERE status IN('pending','approved') GROUP BY event_date ORDER BY c DESC,event_date DESC LIMIT 1")->fetch_assoc();$summary['peak_date']=$peak?$peak['event_date'].' ('.$peak['c'].')':'None';}
        return $summary;
    }
}
