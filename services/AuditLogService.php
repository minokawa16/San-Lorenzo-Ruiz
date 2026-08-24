<?php
require_once __DIR__ . '/../includes/audit.php';

final class AuditLogService
{
    public function __construct(private mysqli $db) {}

    public function page(array $filters, int $page=1, int $perPage=50, int $hardLimit=10000): array
    {
        $page=max(1,$page); $perPage=max(10,min(100,$perPage));
        [$where,$types,$values]=$this->where($filters);
        $count=$this->db->prepare("SELECT COUNT(*) c FROM audit_log l LEFT JOIN users u ON u.id=l.user_id $where");
        if($types!=='')$count->bind_param($types,...$values); $count->execute(); $total=(int)($count->get_result()->fetch_assoc()['c']??0); $count->close();
        $offset=($page-1)*$perPage;
        $sql="SELECT l.log_id,l.created_at,l.user_id,COALESCE(u.fullname,'System') actor,l.action,l.table_name,l.record_id,l.old_value,l.new_value,l.ip_address,l.correlation_id,l.component,l.event FROM audit_log l LEFT JOIN users u ON u.id=l.user_id $where ORDER BY l.created_at DESC,l.log_id DESC LIMIT ? OFFSET ?";
        $stmt=$this->db->prepare($sql); $bindTypes=$types.'ii'; $bind=[...$values,$perPage,$offset]; $stmt->bind_param($bindTypes,...$bind); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach($rows as &$row){$row['old_value']=tugonRedactSensitive((string)$row['old_value']);$row['new_value']=tugonRedactSensitive((string)$row['new_value']);} unset($row);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage)),'truncated'=>$total>$hardLimit,'limit'=>$hardLimit];
    }

    public function exportRows(array $filters, int $limit=10000): array
    {
        [$where,$types,$values]=$this->where($filters); $limit=max(1,min(10000,$limit));
        $stmt=$this->db->prepare("SELECT l.created_at,COALESCE(u.fullname,'System') actor,l.action,l.table_name,l.record_id,l.correlation_id,l.component,l.event,l.ip_address FROM audit_log l LEFT JOIN users u ON u.id=l.user_id $where ORDER BY l.created_at DESC,l.log_id DESC LIMIT ?");
        $bindTypes=$types.'i';$bind=[...$values,$limit];$stmt->bind_param($bindTypes,...$bind);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
    }

    private function where(array $f): array
    {
        $clauses=[];$types='';$values=[];
        if(($f['q']??'')!==''){$like='%'.mb_strimwidth((string)$f['q'],0,100,'').'%';$clauses[]='(l.action LIKE ? OR l.table_name LIKE ? OR l.correlation_id LIKE ? OR u.fullname LIKE ?)';$types.='ssss';array_push($values,$like,$like,$like,$like);}
        if(($f['from']??'')!==''){$clauses[]='l.created_at>=?';$types.='s';$values[]=$f['from'].' 00:00:00';}
        if(($f['to']??'')!==''){$clauses[]='l.created_at<=?';$types.='s';$values[]=$f['to'].' 23:59:59';}
        if(($f['component']??'')!==''){$clauses[]='l.component=?';$types.='s';$values[]=$f['component'];}
        return [$clauses?'WHERE '.implode(' AND ',$clauses):'',$types,$values];
    }
}
