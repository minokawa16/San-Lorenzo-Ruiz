<?php
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../services/ReportService.php';
require_once '../includes/audit.php';
requireLogin(); requirePermission('reports.view');

$page_title='Reports & Analytics';
$report=in_array($_GET['report']??'',ReportService::TYPES,true)?$_GET['report']:'turnaround';
$cleanDate=static fn($v)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$v)?(string)$v:'';
$cleanText=static fn($v)=>preg_match('/^[a-z0-9_ -]{0,80}$/i',(string)$v)?trim((string)$v):'';
$filters=['from'=>$cleanDate($_GET['from']??''),'to'=>$cleanDate($_GET['to']??''),'status'=>$cleanText($_GET['status']??''),'type'=>$cleanText($_GET['type']??'')];
$service=new ReportService($conn); $export=$_GET['export']??'';

if(in_array($export,['csv','pdf'],true)){
    requirePermission('reports.export'); $data=$service->export($report,$filters,10000);
    writeAuditLog($conn,$_SESSION['user_id'],'EXPORT_REPORT','reports',null,null,['report'=>$report,'filters'=>$filters,'format'=>$export,'rows'=>count($data['rows'])],'reports','reports.export');
    $title=ucwords(str_replace('_',' ',$report)).' Report';
    if($export==='csv'){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="tugon-'.$report.'-'.date('Ymd-His').'.csv"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,[$title]);fputcsv($out,['Filters',json_encode(array_filter($filters))]);
        fputcsv($out,array_values($data['columns']));foreach($data['rows'] as $row)fputcsv($out,array_map(static fn($k)=>$row[$k]??'',array_keys($data['columns'])));if($data['truncated'])fputcsv($out,['Showing first 10,000 records.']);fclose($out);exit;
    }
    require_once '../vendor/autoload.php';$html='<h1>'.e($title).'</h1><p>Filters: '.e(json_encode(array_filter($filters))).'</p><table width="100%" border="1" cellspacing="0" cellpadding="4"><thead><tr>';
    foreach($data['columns'] as $label)$html.='<th>'.e($label).'</th>';$html.='</tr></thead><tbody>';foreach($data['rows'] as $row){$html.='<tr>';foreach(array_keys($data['columns']) as $key)$html.='<td>'.e((string)($row[$key]??'')).'</td>';$html.='</tr>';}$html.='</tbody></table>';if($data['truncated'])$html.='<p>Showing first 10,000 records.</p>';
    $pdf=new Dompdf\Dompdf(['isRemoteEnabled'=>false]);$pdf->loadHtml($html);$pdf->setPaper('A4','landscape');$pdf->render();$pdf->stream('tugon-'.$report.'-'.date('Ymd-His').'.pdf',['Attachment'=>true]);exit;
}

$data=$service->run($report,$filters,max(1,(int)($_GET['page']??1)),50);
$labels=['turnaround'=>'Request Turnaround','pending_overdue'=>'Pending & Overdue','rejections'=>'Rejections & Resubmissions','reservations'=>'Reservation Utilization','certificates'=>'Certificate Lifecycle','notifications'=>'Notification Delivery'];
$queryBase=array_filter(array_merge(['report'=>$report],$filters),static fn($v)=>$v!=='');
include '../templates/header.php';
?>
<div class="container-fluid py-4 reports-page">
  <section class="pds-hero mb-3"><div><span class="pds-eyebrow"><i class="fas fa-chart-line" aria-hidden="true"></i> Operational evidence</span><h1>Reports & Analytics</h1><p>Permission-protected, date-filtered operational reports calculated from workflow timestamps.</p></div></section>
  <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Report types">
    <?php foreach($labels as $key=>$label): ?><a class="btn <?php echo $report===$key?'btn-primary':'btn-outline-primary'; ?>" href="?report=<?php echo e($key); ?>"><?php echo e($label); ?></a><?php endforeach; ?>
  </nav>
  <form class="card card-body mb-3" method="get">
    <input type="hidden" name="report" value="<?php echo e($report); ?>">
    <div class="row g-3 align-items-end">
      <div class="col-sm-6 col-lg-3"><label for="reportFrom" class="form-label">From</label><input id="reportFrom" class="form-control" type="date" name="from" value="<?php echo e($filters['from']); ?>"></div>
      <div class="col-sm-6 col-lg-3"><label for="reportTo" class="form-label">To</label><input id="reportTo" class="form-control" type="date" name="to" value="<?php echo e($filters['to']); ?>"></div>
      <div class="col-sm-6 col-lg-2"><label for="reportStatus" class="form-label">Status</label><input id="reportStatus" class="form-control" name="status" value="<?php echo e($filters['status']); ?>" placeholder="All"></div>
      <div class="col-sm-6 col-lg-2"><label for="reportType" class="form-label">Type</label><input id="reportType" class="form-control" name="type" value="<?php echo e($filters['type']); ?>" placeholder="All"></div>
      <div class="col-lg-2 d-grid"><button class="btn btn-primary" type="submit"><i class="fas fa-filter" aria-hidden="true"></i> Apply filters</button></div>
    </div>
  </form>
  <div class="alert alert-info" role="status"><strong>Active filters:</strong> <?php echo e($filters['from']?:'Any date'); ?> to <?php echo e($filters['to']?:'today'); ?>; Type: <?php echo e($filters['type']?:'All'); ?>; Status: <?php echo e($filters['status']?:'All'); ?>. <?php echo number_format($data['total']); ?> matching records.</div>
  <?php if($data['truncated']): ?><div class="alert alert-warning" role="alert">Exports show the first <?php echo number_format($data['limit']); ?> records; the full filtered count is <?php echo number_format($data['total']); ?>.</div><?php endif; ?>
  <?php if(!empty($data['summary'])): ?><section class="row g-3 mb-3" aria-label="Report summary"><?php foreach($data['summary'] as $key=>$value): ?><div class="col-6 col-lg"><div class="card card-body h-100"><small class="text-muted"><?php echo e(ucwords(str_replace('_',' ',$key))); ?></small><strong class="fs-4"><?php echo e((string)($value??0)); ?></strong></div></div><?php endforeach; ?></section><?php endif; ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php if(hasPermission('reports.export')): ?><a class="btn btn-outline-primary" href="?<?php echo e(http_build_query(array_merge($queryBase,['export'=>'csv']))); ?>"><i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV</a><a class="btn btn-outline-primary" href="?<?php echo e(http_build_query(array_merge($queryBase,['export'=>'pdf']))); ?>"><i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF</a><?php endif; ?>
  </div>
  <section class="card"><div class="card-header"><h2 class="h5 mb-0"><?php echo e($labels[$report]); ?></h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><?php foreach($data['columns'] as $label): ?><th scope="col"><?php echo e($label); ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php if(!$data['rows']): ?><tr><td colspan="<?php echo count($data['columns']); ?>" class="text-center py-5"><strong>No report records found.</strong><div class="text-muted">Try a wider date range or clear a filter.</div></td></tr><?php endif; ?>
  <?php foreach($data['rows'] as $row): ?><tr><?php foreach(array_keys($data['columns']) as $key): ?><td><?php echo e((string)($row[$key]??'—')); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div></section>
  <?php if($data['pages']>1): ?><nav class="mt-3" aria-label="Report pages"><ul class="pagination flex-wrap"><?php for($p=max(1,$data['page']-2);$p<=min($data['pages'],$data['page']+2);$p++): ?><li class="page-item <?php echo $p===$data['page']?'active':''; ?>"><a class="page-link" href="?<?php echo e(http_build_query(array_merge($queryBase,['page'=>$p]))); ?>"><?php echo $p; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
</div>
<?php include '../templates/footer.php'; ?>
