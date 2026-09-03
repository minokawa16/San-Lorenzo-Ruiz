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
 $pdfFilters=['from'=>$filters['from'],'to'=>$filters['to'],'status'=>'','type'=>$filters['component']?:'','q'=>$filters['q']];
 ReportPdfGenerator::stream('audit_log','Audit Log Report',$pdfFilters,$pdfData,$generatedBy,'landscape','Security, Transactions & System Activity Logs');
}
$data=$service->page($filters,max(1,(int)($_GET['page']??1)),50);$base=array_filter($filters,static fn($v)=>$v!=='');include '../templates/header.php';
?>
<style>
  .log-panel-card {
    border: 1px solid #e4e7ec;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 12px 28px rgba(16, 24, 40, .06);
    overflow: hidden;
  }
  .log-panel-header {
    background: #ffffff;
    border-bottom: 1px solid #e4e7ec;
    padding: 14px 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    transition: background-color 0.15s ease-in-out;
  }
  .log-panel-header:hover {
    background-color: #fcfaf7;
  }
  .log-panel-header:focus-visible {
    outline: none;
    background-color: #fbf7f0;
    box-shadow: inset 0 0 0 2px #7a5214;
  }
  .log-toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    border-color: #d0d5dd;
    color: #475467;
    background: #ffffff;
    pointer-events: none;
    transition: all 0.15s ease-in-out;
  }
  .log-panel-header:hover .log-toggle-btn {
    border-color: #7a5214;
    color: #7a5214;
  }
  .log-panel-body {
    transition: height 0.25s ease-in-out, opacity 0.2s ease-in-out;
  }
  .logs-scroll-container {
    max-height: 520px;
    overflow-y: auto;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: #6d4c1b #f4ede4;
  }
  .logs-scroll-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
  }
  .logs-scroll-container::-webkit-scrollbar-track {
    background: #f4ede4;
    border-radius: 4px;
  }
  .logs-scroll-container::-webkit-scrollbar-thumb {
    background: #6d4c1b;
    border-radius: 4px;
  }
  .logs-scroll-container::-webkit-scrollbar-thumb:hover {
    background: #543912;
  }
  .log-table {
    margin-bottom: 0;
  }
  .log-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8fafc;
    color: #475467;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1.5px solid #e4e7ec;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  }
  .log-table td {
    font-size: 0.86rem;
    vertical-align: middle;
    padding: 10px 14px;
  }
</style>

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
  <form class="card card-body mb-3" method="get">
    <div class="row g-3 align-items-end">
      <div class="col-lg-4"><label class="form-label" for="auditQ">Search</label><input id="auditQ" class="form-control" type="search" name="q" value="<?php echo e($filters['q']); ?>" placeholder="Action, actor, table, correlation ID"></div>
      <div class="col-sm-6 col-lg-2"><label class="form-label" for="auditFrom">From</label><input id="auditFrom" class="form-control" type="date" name="from" value="<?php echo e($filters['from']); ?>"></div>
      <div class="col-sm-6 col-lg-2"><label class="form-label" for="auditTo">To</label><input id="auditTo" class="form-control" type="date" name="to" value="<?php echo e($filters['to']); ?>"></div>
      <div class="col-sm-6 col-lg-2"><label class="form-label" for="auditComponent">Component</label><input id="auditComponent" class="form-control" name="component" value="<?php echo e($filters['component']); ?>" placeholder="All"></div>
      <div class="col-lg-2 d-grid"><button class="btn btn-primary" type="submit">Apply filters</button></div>
    </div>
  </form>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div role="status"><strong><?php echo number_format($data['total']); ?></strong> matching events; page <?php echo $data['page']; ?> of <?php echo $data['pages']; ?>.</div>
    <?php if(hasPermission('audit.export')): ?>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="?<?php echo e(http_build_query(array_merge($base,['export'=>'csv']))); ?>"><i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV</a>
        <a class="btn btn-outline-danger" href="?<?php echo e(http_build_query(array_merge($base,['export'=>'pdf']))); ?>"><i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if($data['truncated']): ?>
    <div class="alert alert-warning">Exports are limited to the first <?php echo number_format($data['limit']); ?> matching records.</div>
  <?php endif; ?>

<?php
function renderAuditDetailsSimple(array $row): string {
    $new = json_decode((string)($row['new_value'] ?? ''), true);
    $old = json_decode((string)($row['old_value'] ?? ''), true);

    if (is_array($new) && !empty($new)) {
        $items = [];
        foreach ($new as $k => $v) {
            $formattedKey = ucwords(str_replace('_', ' ', (string)$k));
            if (is_array($v)) $v = json_encode($v);
            if (is_null($v)) $v = 'null';
            if (is_bool($v)) $v = $v ? 'Yes' : 'No';

            if (is_array($old) && isset($old[$k]) && $old[$k] !== $v) {
                $oldVal = is_array($old[$k]) ? json_encode($old[$k]) : (string)$old[$k];
                $items[] = '<span class="text-muted">' . e($formattedKey) . ':</span> <span class="text-decoration-line-through text-secondary">' . e(mb_strimwidth($oldVal, 0, 30, '...')) . '</span> &rarr; <strong class="text-dark">' . e(mb_strimwidth((string)$v, 0, 30, '...')) . '</strong>';
            } else {
                $items[] = '<span class="text-muted">' . e($formattedKey) . ':</span> <strong class="text-dark">' . e(mb_strimwidth((string)$v, 0, 40, '...')) . '</strong>';
            }
        }
        $shown = array_slice($items, 0, 2);
        $res = implode('<br>', $shown);
        if (count($items) > 2) {
            $res .= ' <small class="text-muted">(+' . (count($items) - 2) . ' more)</small>';
        }
        return $res;
    }

    $rawNew = trim((string)($row['new_value'] ?? ''));
    if ($rawNew !== '' && $rawNew !== '{}' && $rawNew !== '[]') {
        return '<span class="text-dark">' . e(mb_strimwidth($rawNew, 0, 60, '...')) . '</span>';
    }

    return '<span class="text-muted small">Standard activity</span>';
}

function renderAuditCorrelationSimple(?string $corrId): string {
    $corr = trim((string)$corrId);
    if ($corr === '' || $corr === 'legacy') {
        return '<span class="text-muted small">—</span>';
    }
    $short = substr($corr, 0, 8);
    return '<span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.78rem;" title="Tracking ID: ' . e($corr) . '"><i class="fas fa-fingerprint me-1 text-muted"></i>#' . e($short) . '</span>';
}
?>

  <section class="card log-panel-card mb-4" id="auditLogsCard">
    <div class="card-header log-panel-header" id="auditLogsHeader" role="button" tabindex="0" aria-expanded="true" aria-controls="auditLogsBody">
      <div class="d-flex align-items-center gap-2">
        <h2 class="h5 mb-0"><i class="fas fa-list-check"></i> Audit Events Table</h2>
        <span class="badge bg-secondary rounded-pill px-2 py-1" style="font-size: 0.78rem;">
          <?php echo number_format($data['total']); ?> records
        </span>
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary log-toggle-btn" id="auditLogsToggle" aria-label="Toggle Audit Logs">
        <span class="toggle-text">Hide</span>
        <i class="fas fa-chevron-down toggle-icon"></i>
      </button>
    </div>
    <div class="card-body p-0 log-panel-body" id="auditLogsBody">
      <div class="table-responsive logs-scroll-container">
        <table class="table table-hover align-middle mb-0 log-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Actor</th>
              <th>Action</th>
              <th>Target</th>
              <th>Details</th>
              <th>Tracking Ref</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$data['rows']): ?>
              <tr><td colspan="6" class="text-center py-5"><strong>No audit events found.</strong><div class="text-muted">Clear filters or select another date range.</div></td></tr>
            <?php endif; ?>
            <?php foreach($data['rows'] as $row): ?>
              <tr>
                <td><?php echo e(formatDateTime($row['created_at'])); ?></td>
                <td><strong><?php echo e($row['actor']); ?></strong></td>
                <td>
                  <span class="badge bg-secondary"><i class="fas fa-circle-info" aria-hidden="true"></i> <?php echo e(ucwords(strtolower(str_replace('_',' ',$row['action'])))); ?></span>
                  <small class="d-block text-muted"><?php echo e($row['component'].':'.$row['event']); ?></small>
                </td>
                <td><?php echo e(($row['table_name']?:'system').($row['record_id']?' #'.$row['record_id']:'')); ?></td>
                <td><?php echo renderAuditDetailsSimple($row); ?></td>
                <td><?php echo renderAuditCorrelationSimple($row['correlation_id'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <?php if($data['pages']>1): ?>
    <nav class="mt-3" aria-label="Audit pages">
      <ul class="pagination flex-wrap">
        <?php for($p=max(1,$data['page']-2);$p<=min($data['pages'],$data['page']+2);$p++): ?>
          <li class="page-item <?php echo $p===$data['page']?'active':''; ?>"><a class="page-link" href="?<?php echo e(http_build_query(array_merge($base,['page'=>$p]))); ?>"><?php echo $p; ?></a></li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var header = document.getElementById('auditLogsHeader');
    var body = document.getElementById('auditLogsBody');
    if (!header || !body) return;

    var toggleBtn = header.querySelector('.log-toggle-btn');
    var textSpan = toggleBtn ? toggleBtn.querySelector('.toggle-text') : null;
    var iconEl = toggleBtn ? toggleBtn.querySelector('.toggle-icon') : null;
    var storageKey = 'tugon_audit_logs_table_state';

    function setPanelState(isExpanded) {
      if (isExpanded) {
        body.style.display = 'block';
        header.setAttribute('aria-expanded', 'true');
        if (textSpan) textSpan.textContent = 'Hide';
        if (iconEl) iconEl.className = 'fas fa-chevron-down toggle-icon';
      } else {
        body.style.display = 'none';
        header.setAttribute('aria-expanded', 'false');
        if (textSpan) textSpan.textContent = 'Show';
        if (iconEl) iconEl.className = 'fas fa-chevron-right toggle-icon';
      }
    }

    // 1. Restore state from localStorage (defaults to expanded)
    var savedState = localStorage.getItem(storageKey);
    if (savedState === 'collapsed') {
      setPanelState(false);
    } else {
      setPanelState(true);
    }

    // 2. Click on header toggles
    header.addEventListener('click', function() {
      var isCurrentlyExpanded = header.getAttribute('aria-expanded') === 'true';
      var newState = !isCurrentlyExpanded;
      setPanelState(newState);
      localStorage.setItem(storageKey, newState ? 'expanded' : 'collapsed');
    });

    // 3. Keyboard accessibility (Enter / Spacebar)
    header.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        header.click();
      }
    });
  });
</script>
<?php include '../templates/footer.php'; ?>
