<?php
require_once '../includes/session.php';require_once '../database/config.php';require_once '../includes/helpers.php';require_once '../services/AuditLogService.php';require_once '../includes/audit.php';
requireLogin();requirePermission('audit.view');$page_title='Audit Logs';
$date=static fn($v)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$v)?(string)$v:'';
$filters=['q'=>trim(mb_strimwidth((string)($_GET['q']??''),0,100,'')),'from'=>$date($_GET['from']??''),'to'=>$date($_GET['to']??''),'component'=>preg_match('/^[a-z0-9._-]{0,80}$/i',(string)($_GET['component']??''))?(string)($_GET['component']??''):''];
$service=new AuditLogService($conn);
$export=$_GET['export']??'';
if(in_array($export,['csv','pdf'],true)){
 requirePermission('audit.export');$rows=$service->exportRows($filters);writeAuditLog($conn,$_SESSION['user_id'],'EXPORT_AUDIT_LOG','audit_log',null,null,['filters'=>$filters,'rows'=>count($rows),'format'=>$export],'audit','audit.export');
 if($export==='csv'){
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="tugon-audit-'.date('Ymd-His').'.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Time','Actor','Action','Component','Event','Table','Record','Correlation ID','IP']);foreach($rows as $r)fputcsv($out,[$r['created_at'],$r['actor'],$r['action'],$r['component'],$r['event'],$r['table_name'],$r['record_id'],$r['correlation_id'],$r['ip_address']]);fclose($out);exit;
 }
 // PDF export
 require_once '../vendor/autoload.php';
 require_once '../services/ReportPdfGenerator.php';
 $pdfColumns=['created_at'=>'Time','actor'=>'Actor','action'=>'Action','component'=>'Component','event'=>'Event','table_name'=>'Table','record_id'=>'Record','ip_address'=>'IP'];
 $pdfRows=array_map(static fn($r)=>array_intersect_key($r,array_flip(array_keys($pdfColumns))),$rows);
 $pdfData=['columns'=>$pdfColumns,'rows'=>$pdfRows,'total'=>count($pdfRows),'truncated'=>count($pdfRows)>=10000];
 $generatedBy=!empty($_SESSION['fullname'])?(string)$_SESSION['fullname']:'Parish Administrator';
 $pdfFilters=['from'=>$filters['from'],'to'=>$filters['to'],'status'=>'','type'=>$filters['component']?:''];
 ReportPdfGenerator::stream('audit_log','Audit Log Report',$pdfFilters,$pdfData,$generatedBy,'landscape');
}
$data=$service->page($filters,max(1,(int)($_GET['page']??1)),50);$base=array_filter($filters,static fn($v)=>$v!=='');include '../templates/header.php';
?>
<div class="container-fluid px-0 audit-page">
  <!-- Standardized Section Header -->
  <?php
  $page_header_title = 'Audit Logs';
  $page_header_subtitle = 'Canonical, correlation-aware security, transactions, and business-operation history.';
  $page_header_icon = 'fa-clock-rotate-left';
  $show_back_button = true;
  $back_button_url = BASE_URL . 'admin/dashboard.php';
  include '../includes/page_header.php';
  ?>
 <form class="card card-body mb-3" method="get"><div class="row g-3 align-items-end">
  <div class="col-lg-4"><label class="form-label" for="auditQ">Search</label><input id="auditQ" class="form-control" type="search" name="q" value="<?php echo e($filters['q']); ?>" placeholder="Action, actor, table, correlation ID"></div>
  <div class="col-sm-6 col-lg-2"><label class="form-label" for="auditFrom">From</label><input id="auditFrom" class="form-control" type="date" name="from" value="<?php echo e($filters['from']); ?>"></div>
  <div class="col-sm-6 col-lg-2"><label class="form-label" for="auditTo">To</label><input id="auditTo" class="form-control" type="date" name="to" value="<?php echo e($filters['to']); ?>"></div>
  <div class="col-sm-6 col-lg-2"><label class="form-label" for="auditComponent">Component</label><input id="auditComponent" class="form-control" name="component" value="<?php echo e($filters['component']); ?>" placeholder="All"></div>
  <div class="col-lg-2 d-grid"><button class="btn btn-primary" type="submit">Apply filters</button></div>
 </div></form>
 <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div role="status"><strong><?php echo number_format($data['total']); ?></strong> matching events; page <?php echo $data['page']; ?> of <?php echo $data['pages']; ?>.</div><?php if(hasPermission('audit.export')): ?><div class="d-flex gap-2"><a class="btn btn-outline-primary" href="?<?php echo e(http_build_query(array_merge($base,['export'=>'csv']))); ?>"><i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV</a><a class="btn btn-outline-danger" href="?<?php echo e(http_build_query(array_merge($base,['export'=>'pdf']))); ?>"><i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF</a></div><?php endif; ?></div>
 <?php if($data['truncated']): ?><div class="alert alert-warning">Exports are limited to the first <?php echo number_format($data['limit']); ?> matching records.</div><?php endif; ?>
 <section class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>Change</th><th>Correlation</th></tr></thead><tbody>
 <?php if(!$data['rows']): ?><tr><td colspan="6" class="text-center py-5"><strong>No audit events found.</strong><div class="text-muted">Clear filters or select another date range.</div></td></tr><?php endif; ?>
 <?php foreach($data['rows'] as $row): ?><tr><td><?php echo e(formatDateTime($row['created_at'])); ?></td><td><?php echo e($row['actor']); ?></td><td><span class="badge bg-secondary"><i class="fas fa-circle-info" aria-hidden="true"></i> <?php echo e(ucwords(strtolower(str_replace('_',' ',$row['action'])))); ?></span><small class="d-block text-muted"><?php echo e($row['component'].':'.$row['event']); ?></small></td><td><?php echo e(($row['table_name']?:'system').($row['record_id']?' #'.$row['record_id']:'')); ?></td><td><details><summary>View redacted details</summary><small>Old: <?php echo e(mb_strimwidth((string)$row['old_value'],0,240,'...')); ?><br>New: <?php echo e(mb_strimwidth((string)$row['new_value'],0,240,'...')); ?></small></details></td><td><code><?php echo e($row['correlation_id']?:'legacy'); ?></code></td></tr><?php endforeach; ?>
 </tbody></table></div></section>
 <?php if($data['pages']>1): ?><nav class="mt-3" aria-label="Audit pages"><ul class="pagination flex-wrap"><?php for($p=max(1,$data['page']-2);$p<=min($data['pages'],$data['page']+2);$p++): ?><li class="page-item <?php echo $p===$data['page']?'active':''; ?>"><a class="page-link" href="?<?php echo e(http_build_query(array_merge($base,['page'=>$p]))); ?>"><?php echo $p; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
</div>
<?php include '../templates/footer.php'; ?>
