<?php

final class ReportService
{
    public const TYPES=['all','turnaround','pending_overdue','certificates','notifications'];
    public function __construct(private mysqli $db) {}

    public function run(string $type,array $filters,int $page=1,int $perPage=50):array
    {
        if(!in_array($type,self::TYPES,true))$type='all';$page=max(1,$page);$perPage=max(10,min(100,$perPage));$offset=($page-1)*$perPage;
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
            'all'=>["r.request_id,r.reference_number,r.request_type,r.status,r.priority,r.date_requested report_date,COALESCE(u.fullname,'Parishioner') requester_name",'FROM requests r LEFT JOIN users u ON u.id=r.user_id',"r.deleted_at IS NULL",['reference_number'=>'Reference','request_type'=>'Type','status'=>'Status','priority'=>'Priority','requester_name'=>'Requester','report_date'=>'Submitted']],
            'pending_overdue'=>["r.request_id,r.reference_number,r.request_type,r.status,r.priority,r.due_date,r.date_requested report_date,CASE WHEN r.due_date<CURRENT_DATE THEN 'Overdue' WHEN r.due_date<=DATE_ADD(CURRENT_DATE,INTERVAL 3 DAY) THEN 'Due soon' ELSE 'Pending' END timing",'FROM requests r',"r.deleted_at IS NULL AND r.status NOT IN ('completed','rejected','cancelled')",['reference_number'=>'Reference','request_type'=>'Type','status'=>'Status','priority'=>'Priority','due_date'=>'Due date','timing'=>'Timing','report_date'=>'Submitted']],
            'certificates'=>["c.certificate_number,c.certificate_type,c.status,c.issued_at,c.released_at,c.revoked_at,c.updated_at report_date",'FROM certificate_issuances c','1=1',['certificate_number'=>'Certificate','certificate_type'=>'Type','status'=>'Status','issued_at'=>'Issued','released_at'=>'Released','revoked_at'=>'Revoked','report_date'=>'Updated']],
            'notifications'=>["nd.delivery_id,nd.channel,nd.status,nd.attempt_count,n.notification_type,nd.sent_at,nd.failure_reason,COALESCE(nd.last_attempt_at,n.created_at) report_date",'FROM notification_deliveries nd JOIN notifications n ON n.notification_id=nd.notification_id','1=1',['channel'=>'Channel','status'=>'Status','notification_type'=>'Type','attempt_count'=>'Attempts','sent_at'=>'Sent','failure_reason'=>'Failure','report_date'=>'Last activity']],
            default=>["r.reference_number,r.request_type,r.status,r.date_requested report_date",'FROM requests r',"r.deleted_at IS NULL",['reference_number'=>'Reference','request_type'=>'Type','status'=>'Status','report_date'=>'Submitted']]
        };
    }

    private function filters(string $type,array $f,string $base):array
    {
        $clauses=[$base];$types='';$values=[];$dateColumn=match($type){'certificates'=>'c.updated_at','notifications'=>'COALESCE(nd.last_attempt_at,n.created_at)',default=>'r.date_requested'};
        if(($f['from']??'')!==''){$clauses[]="$dateColumn>=?";$types.='s';$values[]=$f['from'].' 00:00:00';}
        if(($f['to']??'')!==''){$clauses[]="$dateColumn<=?";$types.='s';$values[]=$f['to'].' 23:59:59';}
        $alias=match($type){'certificates'=>'c','notifications'=>'nd',default=>'r'};
        if(($f['status']??'')!==''){$clauses[]="$alias.status=?";$types.='s';$values[]=$f['status'];}
        if(($f['type']??'')!==''){$col=match($type){'certificates'=>'c.certificate_type','notifications'=>'n.notification_type',default=>'r.request_type'};$clauses[]="$col=?";$types.='s';$values[]=$f['type'];}
        return ['WHERE '.implode(' AND ',$clauses),$types,$values];
    }

    private function summary(string $type,array $f):array
    {
        $range='';$types='';$values=[];$column=match($type){'certificates'=>'c.updated_at','notifications'=>'COALESCE(nd.last_attempt_at,n.created_at)',default=>'r.date_requested'};
        if($column&&($f['from']??'')!==''){$range.=" AND $column>=?";$types.='s';$values[]=$f['from'].' 00:00:00';}if($column&&($f['to']??'')!==''){$range.=" AND $column<=?";$types.='s';$values[]=$f['to'].' 23:59:59';}
        if($type==='all'){$sql="SELECT COUNT(*) AS `total`, SUM(r.status IN('pending','submitted','requirements_review')) AS `pending`, SUM(r.status IN('approved','in_processing','processing','ready_for_pickup')) AS `in_progress`, SUM(r.status IN('completed','released')) AS `completed`, SUM(r.status IN('rejected','cancelled')) AS `rejected` FROM requests r WHERE r.deleted_at IS NULL$range";}
        elseif($type==='turnaround'){$sql="SELECT COUNT(*) AS `total`, SUM(r.status IN('completed','released')) AS `completed`, SUM(r.status IN('pending','submitted','requirements_review')) AS `pending`, SUM(r.status IN('rejected','cancelled')) AS `rejected` FROM requests r WHERE r.deleted_at IS NULL$range";}
        elseif($type==='pending_overdue'){$sql="SELECT COUNT(*) AS `total`, SUM(CASE WHEN r.due_date IS NOT NULL AND r.due_date < CURRENT_DATE THEN 1 ELSE 0 END) AS `overdue`, SUM(CASE WHEN r.due_date IS NOT NULL AND r.due_date >= CURRENT_DATE AND r.due_date <= DATE_ADD(CURRENT_DATE,INTERVAL 3 DAY) THEN 1 ELSE 0 END) AS `due_soon`, SUM(CASE WHEN r.priority IN ('urgent','high') THEN 1 ELSE 0 END) AS `urgent_priority` FROM requests r WHERE r.deleted_at IS NULL AND r.status NOT IN ('completed','rejected','cancelled')$range";}
        elseif($type==='certificates'){$sql="SELECT SUM(c.status='issued') AS `issued`, SUM(c.status='released') AS `released`, SUM(c.status='revoked') AS `revoked`, SUM(c.status='reissued') AS `reissued` FROM certificate_issuances c WHERE 1=1$range";}
        elseif($type==='notifications'){$sql="SELECT SUM(nd.status='sent') AS `sent`, SUM(nd.status='failed') AS `failed`, SUM(nd.status='pending') AS `pending`, SUM(nd.attempt_count>1) AS `retried` FROM notification_deliveries nd JOIN notifications n ON n.notification_id=nd.notification_id WHERE 1=1$range";}
        else return [];
        $stmt=$this->db->prepare($sql);
        if(!$stmt){ throw new Exception("SQL error in summary ({$type}): " . $this->db->error . " | SQL: " . $sql); }
        if($types!=='')$stmt->bind_param($types,...$values);$stmt->execute();$summary=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();
        return $summary;
    }
}
