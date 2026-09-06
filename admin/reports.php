<?php
/**
 * ANALYTICS & REPORTS
 * Comprehensive parish operational statistics, sacramental records,
 * and request monitoring dashboard with Chart.js visualisations.
 */

require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../services/ReportService.php';
require_once '../includes/audit.php';
requireLogin(); requirePermission('reports.view');

$page_title = 'Analytics & Reports';

// ─── Sanitise filter inputs ───────────────────────────────────────────────────
$cleanDate = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
$cleanText = static fn($v) => preg_match('/^[a-z0-9_ -]{0,80}$/i', (string)$v) ? trim((string)$v) : '';
$report  = in_array($_GET['report'] ?? '', ReportService::TYPES, true) ? $_GET['report'] : 'all';
$filters = [
    'from'   => $cleanDate($_GET['from']   ?? ''),
    'to'     => $cleanDate($_GET['to']     ?? ''),
    'status' => $cleanText($_GET['status'] ?? ''),
    'type'   => $cleanText($_GET['type']   ?? ''),
];

// ─── Export (preserve existing export logic) ──────────────────────────────────
require_once '../vendor/autoload.php';
require_once '../services/ReportPdfGenerator.php';
$service = new ReportService($conn);
$export  = $_GET['export'] ?? '';
$labels  = ['all' => 'All Records', 'turnaround' => 'Request Processing Time', 'pending_overdue' => 'Pending & Overdue', 'notifications' => 'Notification Delivery'];

if (in_array($export, ['csv', 'pdf'], true)) {
    requirePermission('reports.export');
    $data = $service->export($report, $filters, 10000);
    writeAuditLog($conn, $_SESSION['user_id'], 'EXPORT_REPORT', 'reports', null, null,
        ['report' => $report, 'filters' => $filters, 'format' => $export, 'rows' => count($data['rows'])], 'reports', 'reports.export');
    $title    = ucwords(str_replace('_', ' ', $report)) . ' Report';
    $subtitle = $labels[$report] ?? '';
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tugon-' . $report . '-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$title]);
        fputcsv($out, ['Filters', json_encode(array_filter($filters))]);
        fputcsv($out, array_values($data['columns']));
        foreach ($data['rows'] as $row) fputcsv($out, array_map(static fn($k) => $row[$k] ?? '', array_keys($data['columns'])));
        if ($data['truncated']) fputcsv($out, ['Showing first 10,000 records.']);
        fclose($out); exit;
    }
    $generatedBy = !empty($_SESSION['fullname']) ? (string)$_SESSION['fullname'] : 'Parish Administrator';
    ReportPdfGenerator::stream($report, $title, $filters, $data, $generatedBy, 'landscape', $subtitle);
}

// ─── KPI Queries ──────────────────────────────────────────────────────────────
// Parishioners: role='user', status 'active'=verified, 'pending_verification'=pending
$kpi_parishioners_total    = 0;
$kpi_parishioners_verified = 0;
$kpi_parishioners_pending  = 0;
$r = $conn->query("SELECT COUNT(*) AS total, SUM(status='active') AS verified, SUM(status='pending_verification') AS pending FROM users WHERE role='user'");
if ($r && $row = $r->fetch_assoc()) {
    $kpi_parishioners_total    = (int)($row['total']    ?? 0);
    $kpi_parishioners_verified = (int)($row['verified'] ?? 0);
    $kpi_parishioners_pending  = (int)($row['pending']  ?? 0);
}

// Sacramental records — using actual table names
$sacrament_counts = ['Baptism' => 0, 'Confirmation' => 0, 'Communion' => 0, 'Marriage' => 0, 'Funeral' => 0];
$sacrament_tables = [
    'Baptism'      => ['baptism_records',       'status'],
    'Confirmation' => ['confirmation_records',   'status'],
    'Communion'    => ['first_communion_records','status'],
    'Marriage'     => ['marriage_records',       'status'],
    'Funeral'      => ['funeral_records',        'status'],
];
foreach ($sacrament_tables as $label => [$table, $col]) {
    $rr = $conn->query("SELECT COUNT(*) AS c FROM `$table` WHERE `$col` != 'archived'");
    if ($rr && $row2 = $rr->fetch_assoc()) $sacrament_counts[$label] = (int)($row2['c'] ?? 0);
}
$kpi_sacraments_total = array_sum($sacrament_counts);

// Requests
$kpi_requests_total   = 0;
$kpi_requests_pending = 0;
$rq = $conn->query("SELECT COUNT(*) AS total, SUM(status IN ('pending','submitted','requirements_review')) AS pending FROM requests WHERE deleted_at IS NULL");
if ($rq && $row3 = $rq->fetch_assoc()) {
    $kpi_requests_total   = (int)($row3['total']   ?? 0);
    $kpi_requests_pending = (int)($row3['pending'] ?? 0);
}

// Calendar events — table is schedule_events, date column is event_date
$kpi_events_month = 0;
$ev = $conn->query("SELECT COUNT(*) AS c FROM schedule_events WHERE YEAR(event_date)=YEAR(CURDATE()) AND MONTH(event_date)=MONTH(CURDATE())");
if ($ev && $row4 = $ev->fetch_assoc()) $kpi_events_month = (int)($row4['c'] ?? 0);

// ─── Chart A: Sacramental Records by Month (last 12 months) ──────────────────
$chart_months = [];
for ($i = 11; $i >= 0; $i--) {
    $chart_months[] = date('Y-m', strtotime("-$i months"));
}
$chart_a_labels = array_map(fn($m) => date('M Y', strtotime($m . '-01')), $chart_months);

$chart_a_datasets = [];
// Correct table names and date column names from actual schema
$sacrament_chart_tables = [
    'Baptism'      => ['baptism_records',       'baptism_date'],
    'Confirmation' => ['confirmation_records',   'confirmation_date'],
    'Communion'    => ['first_communion_records','communion_date'],
    'Marriage'     => ['marriage_records',       'wedding_date'],
    'Funeral'      => ['funeral_records',        'date_of_burial'],
];
$chart_a_colors = ['#C89B3C','#2E7D52','#1E5FA8','#9B2C2C','#6B46C1'];
$ci = 0;
foreach ($sacrament_chart_tables as $label => [$table, $date_col]) {
    $row_data = array_fill(0, 12, 0);
    $col_check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$date_col'");
    if (!$col_check || $col_check->num_rows === 0) { $ci++; continue; }
    $sql = "SELECT DATE_FORMAT(`$date_col`, '%Y-%m') AS ym, COUNT(*) AS c FROM `$table` WHERE `$date_col` >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND `status` != 'archived' GROUP BY ym";
    $res = $conn->query($sql);
    if ($res) {
        while ($rw = $res->fetch_assoc()) {
            $idx = array_search($rw['ym'], $chart_months);
            if ($idx !== false) $row_data[$idx] = (int)$rw['c'];
        }
    }
    $chart_a_datasets[] = [
        'label'           => $label,
        'data'            => $row_data,
        'backgroundColor' => $chart_a_colors[$ci] . 'CC',
        'borderColor'     => $chart_a_colors[$ci],
        'borderWidth'     => 1,
        'borderRadius'    => 4,
    ];
    $ci++;
}

// ─── Chart B: Request Status Breakdown ────────────────────────────────────────
$chart_b_data   = [0, 0, 0, 0];
$chart_b_labels = ['Pending', 'In Progress', 'Completed', 'Rejected'];
$rb = $conn->query("SELECT SUM(status IN ('pending','submitted','requirements_review')) AS pending, SUM(status IN ('approved','in_processing','processing','ready_for_pickup')) AS in_progress, SUM(status IN ('completed','released')) AS completed, SUM(status IN ('rejected','cancelled')) AS rejected FROM requests WHERE deleted_at IS NULL");
if ($rb && $row5 = $rb->fetch_assoc()) {
    $chart_b_data = [(int)$row5['pending'], (int)$row5['in_progress'], (int)$row5['completed'], (int)$row5['rejected']];
}

// ─── Chart C: Top Requested Service/Certificate Types ────────────────────────
$chart_c_labels = [];
$chart_c_data   = [];
$rc = $conn->query("SELECT request_type, COUNT(*) AS c FROM requests WHERE deleted_at IS NULL GROUP BY request_type ORDER BY c DESC LIMIT 8");
if ($rc) {
    while ($row6 = $rc->fetch_assoc()) {
        $chart_c_labels[] = ucwords(str_replace('_', ' ', $row6['request_type']));
        $chart_c_data[]   = (int)$row6['c'];
    }
}

// ─── Chart D: Parishioner Registration Growth (last 12 months) ───────────────
$chart_d_data = array_fill(0, 12, 0);
$rd = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS c FROM users WHERE role='user' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym");
if ($rd) {
    while ($row7 = $rd->fetch_assoc()) {
        $idx = array_search($row7['ym'], $chart_months);
        if ($idx !== false) $chart_d_data[$idx] = (int)$row7['c'];
    }
}

// ─── Master table data ────────────────────────────────────────────────────────
$tableData = $service->run($report, $filters, max(1, (int)($_GET['page'] ?? 1)), 50);
$queryBase = array_filter(array_merge(['report' => $report], $filters), static fn($v) => $v !== '');

include '../templates/header.php';
?>
<style>
:root {
    --parish-gold:      #C89B3C;
    --parish-gold-light:#F5E8C0;
    --parish-gold-dim:  rgba(200,155,60,0.12);
    --parish-green:     #2E3A2D;
    --parish-green-mid: #3D5C3A;
    --radius-card:      14px;
    --shadow-card:      0 4px 20px rgba(20,29,20,0.09);
}
.analytics-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}
.analytics-kpi-card {
    background: #fff;
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-card);
    border: 1px solid #e8ecf0;
    padding: 22px 24px 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    position: relative;
    overflow: hidden;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.analytics-kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(20,29,20,0.14); }
.analytics-kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--parish-gold), var(--parish-green-mid));
    border-radius: var(--radius-card) var(--radius-card) 0 0;
}
.kpi-icon-wrap {
    width: 46px; height: 46px;
    border-radius: 12px;
    background: var(--parish-gold-dim);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 4px;
}
.kpi-icon-wrap i { font-size: 1.2rem; color: var(--parish-gold); }
.kpi-number { font-size: 2rem; font-weight: 800; color: var(--parish-green); line-height: 1; font-family: 'Playfair Display', Georgia, serif; }
.kpi-label { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #7a8694; }
.kpi-sub { font-size: 0.78rem; color: #9aa5b4; margin-top: 2px; }
.kpi-badge-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 2px; }
.kpi-badge { font-size: 0.72rem; padding: 2px 9px; border-radius: 20px; font-weight: 600; }
.kpi-badge-green  { background: #DCFCE7; color: #15803D; }
.kpi-badge-amber  { background: #FEF9C3; color: #92400E; }
.kpi-badge-red    { background: #FEE2E2; color: #B91C1C; }
.kpi-badge-blue   { background: #DBEAFE; color: #1D4ED8; }
.kpi-badge-gold   { background: var(--parish-gold-dim); color: #7A5214; }
.analytics-chart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 28px;
}
@media (max-width: 900px) { .analytics-chart-grid { grid-template-columns: 1fr; } }
.analytics-chart-card {
    background: #fff;
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-card);
    border: 1px solid #e8ecf0;
    padding: 22px 20px 18px;
}
.analytics-chart-card.chart-full-width { grid-column: 1 / -1; }
.chart-card-title { font-size: 0.84rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--parish-green); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
.chart-card-title i { color: var(--parish-gold); }
.chart-card-subtitle { font-size: 0.76rem; color: #9aa5b4; margin-bottom: 16px; }
.chart-container canvas { max-height: 280px; }
.analytics-table-section { background: #fff; border-radius: var(--radius-card); box-shadow: var(--shadow-card); border: 1px solid #e8ecf0; overflow: hidden; margin-bottom: 28px; }
.analytics-table-header { padding: 18px 22px 14px; border-bottom: 1px solid #e8ecf0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: #fafbfc; }
.analytics-table-title { font-size: 0.9rem; font-weight: 700; color: var(--parish-green); display: flex; align-items: center; gap: 8px; }
.analytics-table-title i { color: var(--parish-gold); }
.analytics-export-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.analytics-filter-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; padding: 16px 22px; border-bottom: 1px solid #f0f2f5; background: #fdfeff; }
.analytics-filter-row label { font-size: 0.73rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #7a8694; display: block; margin-bottom: 4px; }
.analytics-filter-row select,
.analytics-filter-row input[type="date"],
.analytics-filter-row input[type="text"] { border: 1px solid #d5dde6; border-radius: 8px; padding: 7px 10px; font-size: 0.83rem; background: #fff; color: #334155; outline: none; transition: border-color 0.15s; }
.analytics-filter-row select:focus,
.analytics-filter-row input:focus { border-color: var(--parish-gold); }
.btn-analytics-filter { background: var(--parish-green); color: #fff; border: none; border-radius: 8px; padding: 8px 16px; font-size: 0.83rem; font-weight: 600; cursor: pointer; transition: background 0.15s; display: flex; align-items: center; gap: 6px; }
.btn-analytics-filter:hover { background: #3D5C3A; }
.btn-analytics-reset { background: #f1f5f9; color: #475467; border: 1px solid #d5dde6; border-radius: 8px; padding: 8px 14px; font-size: 0.83rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.15s; display: inline-flex; align-items: center; gap: 6px; }
.btn-analytics-reset:hover { background: #e2e8f0; color: #334155; }
.analytics-scroll-wrapper { max-height: 420px; overflow: auto; scrollbar-width: thin; scrollbar-color: #C89B3C #f4ede4; }
.analytics-data-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.analytics-data-table thead th { position: sticky; top: 0; z-index: 5; background: #f8fafc; color: #344054; font-size: 0.73rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 11px 14px; border-bottom: 1.5px solid #e4e7ec; white-space: nowrap; }
.analytics-data-table tbody tr { transition: background 0.1s; }
.analytics-data-table tbody tr:hover { background: #fafbfd; }
.analytics-data-table tbody td { padding: 10px 14px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; color: #475467; }
.analytics-data-table tbody tr:last-child td { border-bottom: none; }
.analytics-pagination { padding: 14px 22px; border-top: 1px solid #f0f2f5; }
.status-pill { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: capitalize; }
.status-pill.pending, .status-pill.submitted { background: #FEF3C7; color: #92400E; }
.status-pill.approved { background: #D1FAE5; color: #065F46; }
.status-pill.completed, .status-pill.released { background: #DBEAFE; color: #1E40AF; }
.status-pill.rejected, .status-pill.cancelled { background: #FEE2E2; color: #991B1B; }
.status-pill.in_processing, .status-pill.processing { background: #E0F2FE; color: #0369A1; }
.analytics-page-header { margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid #e8ecf0; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.analytics-page-header-left h2 { font-size: 1.4rem; font-weight: 700; color: var(--parish-green); margin: 0 0 4px; font-family: 'Playfair Display', Georgia, serif; display: flex; align-items: center; gap: 10px; }
.analytics-page-header-left h2 .icon-badge { width: 38px; height: 38px; border-radius: 10px; background: var(--parish-gold-dim); display: inline-flex; align-items: center; justify-content: center; }
.analytics-page-header-left h2 .icon-badge i { font-size: 1rem; color: var(--parish-gold); }
.analytics-page-header-left p { font-size: 0.83rem; color: #7a8694; margin: 0; }
.btn-export-pdf { background: linear-gradient(135deg, var(--parish-gold), #A07828); color: #fff; border: none; border-radius: 9px; padding: 10px 20px; font-size: 0.83rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: opacity 0.15s, transform 0.15s; box-shadow: 0 2px 8px rgba(200,155,60,0.3); }
.btn-export-pdf:hover { opacity: 0.9; transform: translateY(-1px); color: #fff; }
.btn-export-csv { background: #fff; color: var(--parish-green); border: 1.5px solid var(--parish-gold); border-radius: 9px; padding: 9px 18px; font-size: 0.83rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: background 0.15s, transform 0.15s; }
.btn-export-csv:hover { background: var(--parish-gold-dim); transform: translateY(-1px); color: var(--parish-green); }
.report-type-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
.report-type-pill { padding: 5px 14px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; text-decoration: none; border: 1.5px solid #d5dde6; color: #475467; background: #fff; transition: all 0.15s; }
.report-type-pill:hover { border-color: var(--parish-gold); color: var(--parish-green); background: var(--parish-gold-dim); }
.report-type-pill.active { background: var(--parish-green); color: #fff; border-color: var(--parish-green); }
</style>

<div class="container-fluid px-0">

  <div class="analytics-page-header mb-4">
    <div class="analytics-page-header-left">
      <h2>
        <span class="icon-badge" aria-hidden="true"><i class="fas fa-chart-line"></i></span>
        Analytics &amp; Reports
      </h2>
      <p>Parish operational statistics, sacramental records, and request monitoring.</p>
    </div>
    <?php if (hasPermission('reports.export')): ?>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn-export-pdf" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'pdf']))); ?>">
        <i class="fas fa-file-pdf"></i> Export PDF
      </a>
      <a class="btn-export-csv" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'csv']))); ?>">
        <i class="fas fa-file-csv"></i> Export CSV
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- KPI CARDS -->
  <div class="analytics-kpi-grid">
    <div class="analytics-kpi-card">
      <div class="kpi-icon-wrap"><i class="fas fa-users"></i></div>
      <div class="kpi-number"><?php echo number_format($kpi_parishioners_total); ?></div>
      <div class="kpi-label">Registered Parishioners</div>
      <div class="kpi-badge-row">
        <span class="kpi-badge kpi-badge-green"><i class="fas fa-circle-check" style="font-size:.65em;"></i> <?php echo number_format($kpi_parishioners_verified); ?> Verified</span>
        <?php if ($kpi_parishioners_pending > 0): ?>
        <span class="kpi-badge kpi-badge-amber"><?php echo number_format($kpi_parishioners_pending); ?> Pending</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="analytics-kpi-card">
      <div class="kpi-icon-wrap"><i class="fas fa-book-bible"></i></div>
      <div class="kpi-number"><?php echo number_format($kpi_sacraments_total); ?></div>
      <div class="kpi-label">Sacramental Records</div>
      <div class="kpi-badge-row">
        <?php foreach ($sacrament_counts as $type => $cnt): ?>
        <span class="kpi-badge kpi-badge-gold"><?php echo $cnt; ?> <?php echo $type; ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="analytics-kpi-card">
      <div class="kpi-icon-wrap"><i class="fas fa-inbox"></i></div>
      <div class="kpi-number"><?php echo number_format($kpi_requests_total); ?></div>
      <div class="kpi-label">Service &amp; Certificate Requests</div>
      <div class="kpi-badge-row">
        <?php if ($kpi_requests_pending > 0): ?>
        <span class="kpi-badge kpi-badge-red"><?php echo $kpi_requests_pending; ?> Pending Action</span>
        <?php else: ?>
        <span class="kpi-badge kpi-badge-green">All requests processed</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="analytics-kpi-card">
      <div class="kpi-icon-wrap"><i class="fas fa-calendar-days"></i></div>
      <div class="kpi-number"><?php echo number_format($kpi_events_month); ?></div>
      <div class="kpi-label">Events This Month</div>
      <div class="kpi-sub">Active scheduled calendar events</div>
    </div>
  </div>

  <!-- CHARTS -->
  <div class="analytics-chart-grid">
    <div class="analytics-chart-card chart-full-width">
      <div class="chart-card-title"><i class="fas fa-chart-bar"></i> Sacramental Records Administered by Month</div>
      <div class="chart-card-subtitle">Volume of each sacrament over the last 12 months</div>
      <div class="chart-container">
        <canvas id="chartSacraments" aria-label="Sacramental records administered by month" role="img"></canvas>
      </div>
    </div>
    <div class="analytics-chart-card">
      <div class="chart-card-title"><i class="fas fa-chart-pie"></i> Request Status Breakdown</div>
      <div class="chart-card-subtitle">Current status of all service &amp; certificate requests</div>
      <div class="chart-container" style="display:flex;align-items:center;justify-content:center;">
        <canvas id="chartRequestStatus" aria-label="Request status breakdown" role="img"></canvas>
      </div>
    </div>
    <div class="analytics-chart-card">
      <div class="chart-card-title"><i class="fas fa-bars-staggered"></i> Most Requested Services &amp; Certificates</div>
      <div class="chart-card-subtitle">Ranked by total request volume</div>
      <div class="chart-container">
        <canvas id="chartTopServices" aria-label="Top requested service types" role="img"></canvas>
      </div>
    </div>
    <div class="analytics-chart-card chart-full-width">
      <div class="chart-card-title"><i class="fas fa-chart-area"></i> Parishioner Registration Growth</div>
      <div class="chart-card-subtitle">Newly registered parishioners per month over the last 12 months</div>
      <div class="chart-container">
        <canvas id="chartParishGrowth" aria-label="Parishioner registration growth" role="img"></canvas>
      </div>
    </div>
  </div>

  <!-- MASTER RECORDS TABLE -->
  <div class="analytics-table-section">
    <div class="analytics-table-header">
      <div class="analytics-table-title">
        <i class="fas fa-table-list"></i>
        All Parish Records &amp; Activity Log
        <span class="kpi-badge kpi-badge-blue ms-1"><?php echo number_format($tableData['total']); ?> records</span>
      </div>
      <?php if (hasPermission('reports.export')): ?>
      <div class="analytics-export-btns">
        <a class="btn-export-pdf" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'pdf']))); ?>">
          <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a class="btn-export-csv" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'csv']))); ?>">
          <i class="fas fa-file-csv"></i> CSV
        </a>
      </div>
      <?php endif; ?>
    </div>
    <div class="px-3 pt-3">
      <div class="report-type-nav">
        <?php foreach ($labels as $key => $label): ?>
        <a class="report-type-pill <?php echo $report === $key ? 'active' : ''; ?>" href="?report=<?php echo e($key); ?>"><?php echo e($label); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <form method="get" class="analytics-filter-row">
      <input type="hidden" name="report" value="<?php echo e($report); ?>">
      <div>
        <label for="filterFrom">From</label>
        <input id="filterFrom" type="date" name="from" value="<?php echo e($filters['from']); ?>">
      </div>
      <div>
        <label for="filterTo">To</label>
        <input id="filterTo" type="date" name="to" value="<?php echo e($filters['to']); ?>">
      </div>
      <div>
        <label for="filterStatus">Status</label>
        <select id="filterStatus" name="status">
          <option value="">All Statuses</option>
          <?php foreach (['pending','submitted','approved','in_processing','completed','released','rejected','cancelled'] as $st): ?>
          <option value="<?php echo $st; ?>" <?php echo $filters['status'] === $st ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $st)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filterType">Type</label>
        <input id="filterType" type="text" name="type" value="<?php echo e($filters['type']); ?>" placeholder="e.g. baptismal_cert">
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <button type="submit" class="btn-analytics-filter"><i class="fas fa-filter"></i> Apply</button>
        <a class="btn-analytics-reset" href="?report=<?php echo e($report); ?>"><i class="fas fa-rotate-left"></i> Reset</a>
      </div>
    </form>
    <?php if ($tableData['truncated']): ?>
    <div class="alert alert-warning mx-3 my-2 py-2" role="alert" style="font-size:0.82rem;">
      Showing first <?php echo number_format($tableData['limit']); ?> records. Total matching: <?php echo number_format($tableData['total']); ?>.
    </div>
    <?php endif; ?>
    <div class="analytics-scroll-wrapper">
      <table class="analytics-data-table">
        <thead>
          <tr>
            <?php foreach ($tableData['columns'] as $colLabel): ?>
            <th scope="col"><?php echo e($colLabel); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tableData['rows']): ?>
          <tr>
            <td colspan="<?php echo count($tableData['columns']); ?>" class="text-center py-5">
              <i class="fas fa-inbox" style="font-size:2rem;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
              <strong style="color:#475467;">No records found.</strong>
              <div style="color:#94a3b8;font-size:0.82rem;">Try widening your date range or clearing a filter.</div>
            </td>
          </tr>
          <?php endif; ?>
          <?php foreach ($tableData['rows'] as $tRow): ?>
          <tr>
            <?php foreach (array_keys($tableData['columns']) as $colKey): ?>
            <?php
              $cellVal = (string)($tRow[$colKey] ?? '—');
              if ($colKey === 'status' && $cellVal !== '—') {
                  $slug = strtolower(str_replace([' ', '-'], '_', $cellVal));
                  echo '<td><span class="status-pill ' . e($slug) . '">' . e($cellVal) . '</span></td>';
              } else {
                  echo '<td>' . e($cellVal) . '</td>';
              }
            ?>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($tableData['pages'] > 1): ?>
    <div class="analytics-pagination">
      <nav aria-label="Report pages">
        <ul class="pagination flex-wrap mb-0">
          <?php for ($p = max(1, $tableData['page'] - 2); $p <= min($tableData['pages'], $tableData['page'] + 2); $p++): ?>
          <li class="page-item <?php echo $p === $tableData['page'] ? 'active' : ''; ?>">
            <a class="page-link" href="?<?php echo e(http_build_query(array_merge($queryBase, ['page' => $p]))); ?>"><?php echo $p; ?></a>
          </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
  'use strict';
  const GOLD       = '#C89B3C';
  const GOLD_LIGHT = 'rgba(200,155,60,0.15)';

  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.color = '#64748b';

  const chartACtx = document.getElementById('chartSacraments');
  if (chartACtx) {
    new Chart(chartACtx, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($chart_a_labels); ?>,
        datasets: <?php echo json_encode($chart_a_datasets); ?>
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { padding: 18, boxWidth: 12, font: { size: 12, weight: '600' } } },
          tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + ctx.parsed.y } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 } } },
          y: { beginAtZero: true, grid: { color: '#f0f4f8' }, ticks: { precision: 0, font: { size: 11 } } }
        }
      }
    });
  }

  const chartBCtx = document.getElementById('chartRequestStatus');
  if (chartBCtx) {
    new Chart(chartBCtx, {
      type: 'doughnut',
      data: {
        labels: <?php echo json_encode($chart_b_labels); ?>,
        datasets: [{
          data: <?php echo json_encode($chart_b_data); ?>,
          backgroundColor: ['#F59E0B','#3B82F6','#10B981','#EF4444'],
          borderWidth: 3,
          borderColor: '#ffffff',
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        cutout: '68%',
        plugins: {
          legend: { position: 'right', labels: { padding: 16, boxWidth: 12, font: { size: 12, weight: '600' } } },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                const pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
              }
            }
          }
        }
      }
    });
  }

  const chartCCtx = document.getElementById('chartTopServices');
  if (chartCCtx) {
    const topLabels = <?php echo json_encode($chart_c_labels); ?>;
    const topData   = <?php echo json_encode($chart_c_data); ?>;
    const barColors = topData.map((_, i) => `hsl(${30 + i * 22}, ${55 + (i % 3) * 8}%, ${38 + (i % 2) * 8}%)`);
    new Chart(chartCCtx, {
      type: 'bar',
      data: {
        labels: topLabels,
        datasets: [{ label: 'Total Requests', data: topData, backgroundColor: barColors, borderRadius: 6, borderSkipped: false }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.x + ' requests' } }
        },
        scales: {
          x: { beginAtZero: true, grid: { color: '#f0f4f8' }, ticks: { precision: 0 } },
          y: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
      }
    });
  }

  const chartDCtx = document.getElementById('chartParishGrowth');
  if (chartDCtx) {
    new Chart(chartDCtx, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($chart_a_labels); ?>,
        datasets: [{
          label: 'New Parishioners',
          data: <?php echo json_encode(array_values($chart_d_data)); ?>,
          borderColor: GOLD,
          backgroundColor: GOLD_LIGHT,
          fill: true,
          tension: 0.4,
          pointBackgroundColor: GOLD,
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointRadius: 5,
          pointHoverRadius: 7
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y + ' new registrations' } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 } } },
          y: { beginAtZero: true, grid: { color: '#f0f4f8' }, ticks: { precision: 0, font: { size: 11 } } }
        }
      }
    });
  }
})();
</script>

<?php include '../templates/footer.php'; ?>