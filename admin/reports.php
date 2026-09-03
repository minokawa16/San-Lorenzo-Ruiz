<?php
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../services/ReportService.php';
require_once '../includes/audit.php';
requireLogin(); requirePermission('reports.view');

$page_title='Reports & Analytics';
$report=in_array($_GET['report']??'',ReportService::TYPES,true)?$_GET['report']:'all';
$cleanDate=static fn($v)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$v)?(string)$v:'';
$cleanText=static fn($v)=>preg_match('/^[a-z0-9_ -]{0,80}$/i',(string)$v)?trim((string)$v):'';
$filters=['from'=>$cleanDate($_GET['from']??''),'to'=>$cleanDate($_GET['to']??''),'status'=>$cleanText($_GET['status']??''),'type'=>$cleanText($_GET['type']??'')];
$service=new ReportService($conn); $export=$_GET['export']??'';

require_once '../vendor/autoload.php';
require_once '../services/ReportPdfGenerator.php';

$labels=['all'=>'All Records','turnaround'=>'Request Processing Time','pending_overdue'=>'Pending & Overdue','notifications'=>'Notification Delivery'];

if(in_array($export,['csv','pdf'],true)){
    requirePermission('reports.export'); $data=$service->export($report,$filters,10000);
    writeAuditLog($conn,$_SESSION['user_id'],'EXPORT_REPORT','reports',null,null,['report'=>$report,'filters'=>$filters,'format'=>$export,'rows'=>count($data['rows'])],'reports','reports.export');
    $title=ucwords(str_replace('_',' ',$report)).' Report';
    $subtitle=$labels[$report]??'';
    if($export==='csv'){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="tugon-'.$report.'-'.date('Ymd-His').'.csv"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,[$title]);fputcsv($out,['Filters',json_encode(array_filter($filters))]);
        fputcsv($out,array_values($data['columns']));foreach($data['rows'] as $row)fputcsv($out,array_map(static fn($k)=>$row[$k]??'',array_keys($data['columns'])));if($data['truncated'])fputcsv($out,['Showing first 10,000 records.']);fclose($out);exit;
    }
    $generatedBy = !empty($_SESSION['fullname']) ? (string)$_SESSION['fullname'] : 'Parish Administrator';
    ReportPdfGenerator::stream($report, $title, $filters, $data, $generatedBy, 'landscape', $subtitle);
}

$data=$service->run($report,$filters,max(1,(int)($_GET['page']??1)),50);
$queryBase=array_filter(array_merge(['report'=>$report],$filters),static fn($v)=>$v!=='');
include '../templates/header.php';
?>
<style>
  .report-panel-card {
    border: 1px solid #e4e7ec;
    border-radius: 8px;
    background: #ffffff;
    box-shadow: 0 12px 28px rgba(16, 24, 40, .06);
    overflow: hidden;
    transition: box-shadow 0.2s ease-in-out;
  }
  .report-panel-header {
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
  .report-panel-header:hover {
    background-color: #fcfaf7;
  }
  .report-panel-header:focus-visible {
    outline: none;
    background-color: #fbf7f0;
    box-shadow: inset 0 0 0 2px #7a5214;
  }
  .report-toggle-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1px solid #d0d5dd;
    color: #475467;
    background: #ffffff;
    pointer-events: none;
    transition: all 0.15s ease-in-out;
  }
  .report-panel-header:hover .report-toggle-pill {
    border-color: #7a5214;
    color: #7a5214;
    background-color: #fdfbf7;
  }
  .report-panel-body {
    transition: height 0.25s ease-in-out, opacity 0.2s ease-in-out;
  }
  .report-table-scroll-container {
    max-height: 420px;
    overflow-y: auto;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: #6d4c1b #f4ede4;
  }
  .report-table-scroll-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
  }
  .report-table-scroll-container::-webkit-scrollbar-track {
    background: #f4ede4;
    border-radius: 4px;
  }
  .report-table-scroll-container::-webkit-scrollbar-thumb {
    background: #6d4c1b;
    border-radius: 4px;
  }
  .report-table-scroll-container::-webkit-scrollbar-thumb:hover {
    background: #543912;
  }
  .report-data-table {
    margin-bottom: 0;
  }
  .report-data-table thead th {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #f8fafc;
    color: #344054;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1.5px solid #e4e7ec;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    white-space: nowrap;
  }
  .report-data-table td {
    font-size: 0.86rem;
    vertical-align: middle;
    padding: 10px 14px;
  }
</style>

<div class="container-fluid px-0">
  <!-- Standardized Section Header -->
  <?php
  $page_header_title = 'Reports & Analytics';
  $page_header_subtitle = 'Permission-protected, date-filtered operational reports calculated from workflow timestamps.';
  $page_header_icon = 'fa-chart-line';
  $show_back_button = true;
  $back_button_url = BASE_URL . 'admin/dashboard.php';
  include '../includes/page_header.php';
  ?>
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
      <div class="col-lg-2 d-flex gap-2">
        <button class="btn btn-primary flex-fill" type="submit"><i class="fas fa-filter" aria-hidden="true"></i> Apply</button>
        <a class="btn btn-outline-secondary" href="?report=<?php echo e($report); ?>" title="View all records for this report"><i class="fas fa-list-ul"></i> All</a>
      </div>
    </div>
  </form>
  <div class="alert alert-info" role="status"><strong>Active filters:</strong> <?php echo e($filters['from']?:'Any date'); ?> to <?php echo e($filters['to']?:'today'); ?>; Type: <?php echo e($filters['type']?:'All'); ?>; Status: <?php echo e($filters['status']?:'All'); ?>. <?php echo number_format($data['total']); ?> matching records.</div>
  <?php if($data['truncated']): ?><div class="alert alert-warning" role="alert">Exports show the first <?php echo number_format($data['limit']); ?> records; the full filtered count is <?php echo number_format($data['total']); ?>.</div><?php endif; ?>
  <?php if(!empty($data['summary'])): ?><section class="row g-3 mb-3" aria-label="Report summary"><?php foreach($data['summary'] as $key=>$value): ?><div class="col-6 col-lg"><div class="card card-body h-100"><small class="text-muted"><?php echo e(ucwords(str_replace('_',' ',$key))); ?></small><strong class="fs-4"><?php echo e((string)($value??0)); ?></strong></div></div><?php endforeach; ?></section><?php endif; ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-secondary <?php echo $report === 'all' && empty(array_filter($filters)) ? 'active' : ''; ?>" href="?report=all"><i class="fas fa-list-check" aria-hidden="true"></i> All Reports (All Data)</a>
    <?php if(hasPermission('reports.export')): ?><a class="btn btn-outline-primary" href="?<?php echo e(http_build_query(array_merge($queryBase,['export'=>'csv']))); ?>"><i class="fas fa-file-csv" aria-hidden="true"></i> Export CSV</a><a class="btn btn-outline-primary" href="?<?php echo e(http_build_query(array_merge($queryBase,['export'=>'pdf']))); ?>"><i class="fas fa-file-pdf" aria-hidden="true"></i> Export PDF</a><?php endif; ?>
  </div>

  <section class="card report-panel-card mb-4" data-collapsible-report="<?php echo e($report); ?>" data-total-records="<?php echo intval($data['total']); ?>">
    <div class="card-header report-panel-header" data-report-header role="button" tabindex="0" aria-expanded="true" aria-controls="reportBody_<?php echo e($report); ?>">
      <div class="d-flex align-items-center gap-2">
        <h2 class="h5 mb-0"><i class="fas fa-table-list"></i> <?php echo e($labels[$report]); ?></h2>
        <span class="badge bg-secondary rounded-pill px-2 py-1" style="font-size: 0.78rem;">
          <?php echo number_format($data['total']); ?> records
        </span>
      </div>
      <button type="button" class="report-toggle-pill" data-report-toggle aria-label="Toggle Report Table">
        <span class="toggle-text">Hide Table</span>
        <i class="fas fa-chevron-up toggle-icon"></i>
      </button>
    </div>
    <div class="card-body p-0 report-panel-body" id="reportBody_<?php echo e($report); ?>" data-report-body>
      <div class="table-responsive report-table-scroll-container">
        <table class="table table-hover align-middle mb-0 report-data-table">
          <thead>
            <tr>
              <?php foreach($data['columns'] as $label): ?>
                <th scope="col"><?php echo e($label); ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if(!$data['rows']): ?>
              <tr><td colspan="<?php echo count($data['columns']); ?>" class="text-center py-5"><strong>No report records found.</strong><div class="text-muted">Try a wider date range or clear a filter.</div></td></tr>
            <?php endif; ?>
            <?php foreach($data['rows'] as $row): ?>
              <tr>
                <?php foreach(array_keys($data['columns']) as $key): ?>
                  <td><?php echo e((string)($row[$key]??'—')); ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <?php if($data['pages']>1): ?>
    <nav class="mt-3" aria-label="Report pages">
      <ul class="pagination flex-wrap">
        <?php for($p=max(1,$data['page']-2);$p<=min($data['pages'],$data['page']+2);$p++): ?>
          <li class="page-item <?php echo $p===$data['page']?'active':''; ?>"><a class="page-link" href="?<?php echo e(http_build_query(array_merge($queryBase,['page'=>$p]))); ?>"><?php echo $p; ?></a></li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<script>
/**
 * Modular Collapsible Report Table System
 * Auto-initializes on any element with [data-collapsible-report]
 */
(function() {
    'use strict';

    function initCollapsibleReports() {
        var reportCards = document.querySelectorAll('[data-collapsible-report]');
        
        reportCards.forEach(function(card) {
            var reportKey = card.getAttribute('data-collapsible-report') || 'default';
            var header = card.querySelector('[data-report-header]') || card.querySelector('.card-header');
            var body = card.querySelector('[data-report-body]') || card.querySelector('.report-panel-body, .card-body');
            var toggleBtn = card.querySelector('[data-report-toggle]') || card.querySelector('.report-toggle-pill');
            var totalRecords = parseInt(card.getAttribute('data-total-records') || '0', 10);
            var storageKey = 'report_view_state_' + reportKey;

            if (!header || !body) return;

            header.setAttribute('role', 'button');
            header.setAttribute('tabindex', '0');

            var textSpan = toggleBtn ? toggleBtn.querySelector('.toggle-text') : null;
            var iconEl = toggleBtn ? toggleBtn.querySelector('.toggle-icon') : null;

            function updateUI(isExpanded) {
                if (isExpanded) {
                    body.style.display = 'block';
                    header.setAttribute('aria-expanded', 'true');
                    card.classList.remove('is-collapsed');
                    card.classList.add('is-expanded');
                    if (textSpan) textSpan.textContent = 'Hide Table';
                    if (iconEl) iconEl.className = 'fas fa-chevron-up toggle-icon';
                } else {
                    body.style.display = 'none';
                    header.setAttribute('aria-expanded', 'false');
                    card.classList.remove('is-expanded');
                    card.classList.add('is-collapsed');
                    var formattedCount = isNaN(totalRecords) ? '0' : totalRecords.toLocaleString();
                    if (textSpan) textSpan.textContent = 'Show Table (' + formattedCount + ' records)';
                    if (iconEl) iconEl.className = 'fas fa-chevron-down toggle-icon';
                }
            }

            // Restore state from sessionStorage (defaults to expanded)
            var savedState = sessionStorage.getItem(storageKey);
            var isExpanded = savedState !== 'collapsed';
            updateUI(isExpanded);

            function toggle() {
                var currentlyExpanded = header.getAttribute('aria-expanded') === 'true';
                var newState = !currentlyExpanded;
                updateUI(newState);
                sessionStorage.setItem(storageKey, newState ? 'expanded' : 'collapsed');
            }

            header.addEventListener('click', function(e) {
                if (e.target.closest('a, select, input, form')) return;
                toggle();
            });

            header.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggle();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCollapsibleReports);
    } else {
        initCollapsibleReports();
    }
})();
</script>
<?php include '../templates/footer.php'; ?>
