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

// --- Sanitise filter inputs ------------------------------------------------
$cleanDate = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
$cleanText = static fn($v) => preg_match('/^[a-z0-9_ -]{0,80}$/i', (string)$v) ? trim((string)$v) : '';
$rawReport = $_POST['report'] ?? ($_GET['report'] ?? '');
$report    = in_array($rawReport, ReportService::TYPES, true) ? $rawReport : 'all';
$filters   = [
    'from'   => $cleanDate($_POST['from']   ?? ($_GET['from']   ?? '')),
    'to'     => $cleanDate($_POST['to']     ?? ($_GET['to']     ?? '')),
    'status' => $cleanText($_POST['status'] ?? ($_GET['status'] ?? '')),
    'type'   => $cleanText($_POST['type']   ?? ($_GET['type']   ?? '')),
];
$labels    = [
    'all'             => 'All Records',
    'turnaround'      => 'Request Processing Time',
    'pending_overdue' => 'Pending & Overdue',
    'notifications'   => 'Notification Delivery',
];

// --- KPI Queries (loaded early so export meta has full data) ---------------
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
    'Baptism'      => ['baptism_records',        'status'],
    'Confirmation' => ['confirmation_records',    'status'],
    'Communion'    => ['first_communion_records', 'status'],
    'Marriage'     => ['marriage_records',        'status'],
    'Funeral'      => ['funeral_records',         'status'],
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

// --- Export Logic (PDF & CSV) ---------------------------------------------
require_once '../vendor/autoload.php';
require_once '../services/ReportPdfGenerator.php';
$service = new ReportService($conn);
$export  = $_POST['export'] ?? ($_GET['export'] ?? '');

if (in_array($export, ['csv', 'pdf'], true)) {
    requirePermission('reports.export');
    $data = $service->export($report, $filters, 10000);
    writeAuditLog($conn, $_SESSION['user_id'], 'EXPORT_REPORT', 'reports', null, null,
        ['report' => $report, 'filters' => $filters, 'format' => $export, 'rows' => count($data['rows'])], 'reports', 'reports.export');
    $title    = 'Parish Analytics & Operational Report';
    $subtitle = $labels[$report] ?? 'Parish Operational Statistics';
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

    // Dynamic Chart Image extraction from POST (high-resolution Base64 PNGs)
    $charts = [];
    $rawSacraments    = $_POST['chart_sacraments'] ?? '';
    $rawRequestStatus = $_POST['chart_request_status'] ?? '';
    $rawTopServices   = $_POST['chart_top_services'] ?? '';
    $rawParishGrowth  = $_POST['chart_parish_growth'] ?? '';

    $isValidDataUri = static function ($str): bool {
        return is_string($str) && preg_match('#^data:image/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+/=]+$#', $str) === 1;
    };

    if ($isValidDataUri($rawSacraments))    $charts['sacraments']     = $rawSacraments;
    if ($isValidDataUri($rawRequestStatus)) $charts['request_status'] = $rawRequestStatus;
    if ($isValidDataUri($rawTopServices))   $charts['top_services']   = $rawTopServices;
    if ($isValidDataUri($rawParishGrowth))  $charts['parish_growth']  = $rawParishGrowth;

    $meta = [
        'parishioners_total'    => $kpi_parishioners_total,
        'sacraments_total'      => $kpi_sacraments_total,
        'total_requests'        => $kpi_requests_total,
        'events_month'          => $kpi_events_month,
        'requests_pending'      => $kpi_requests_pending,
        'parishioners_verified' => $kpi_parishioners_verified,
        'parishioners_pending'  => $kpi_parishioners_pending,
    ];

    $generatedBy = !empty($_SESSION['fullname']) ? (string)$_SESSION['fullname'] : 'Parish Administrator';
    ReportPdfGenerator::stream($report, $title, $filters, $data, $generatedBy, 'portrait', $subtitle, $charts, $meta);
}

// --- Chart A: Sacramental Records by Month (last 12 months) ----------------
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

// --- Chart B: Request Status Breakdown ------------------------------------
$chart_b_data   = [0, 0, 0, 0];
$chart_b_labels = ['Pending', 'In Progress', 'Completed', 'Rejected'];
$rb = $conn->query("SELECT SUM(status IN ('pending','submitted','requirements_review')) AS pending, SUM(status IN ('approved','in_processing','processing','ready_for_pickup')) AS in_progress, SUM(status IN ('completed','released')) AS completed, SUM(status IN ('rejected','cancelled')) AS rejected FROM requests WHERE deleted_at IS NULL");
if ($rb && $row5 = $rb->fetch_assoc()) {
    $chart_b_data = [(int)$row5['pending'], (int)$row5['in_progress'], (int)$row5['completed'], (int)$row5['rejected']];
}

// --- Chart C: Top Requested Service/Certificate Types ----------------------
$chart_c_labels = [];
$chart_c_data   = [];
$rc = $conn->query("SELECT request_type, COUNT(*) AS c FROM requests WHERE deleted_at IS NULL GROUP BY request_type ORDER BY c DESC LIMIT 8");
if ($rc) {
    while ($row6 = $rc->fetch_assoc()) {
        $chart_c_labels[] = ucwords(str_replace('_', ' ', $row6['request_type']));
        $chart_c_data[]   = (int)$row6['c'];
    }
}

// --- Chart D: Parishioner Registration Growth (last 12 months) -------------
$chart_d_data = array_fill(0, 12, 0);
$rd = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS c FROM users WHERE role='user' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym");
if ($rd) {
    while ($row7 = $rd->fetch_assoc()) {
        $idx = array_search($row7['ym'], $chart_months);
        if ($idx !== false) $chart_d_data[$idx] = (int)$row7['c'];
    }
}

// --- Master table data -----------------------------------------------------
$tableData = $service->run($report, $filters, max(1, (int)($_GET['page'] ?? 1)), 50);
$queryBase = array_filter(array_merge(['report' => $report], $filters), static fn($v) => $v !== '');

include '../templates/header.php';
?>
<style>
/* --------------------------------------------------------------------------
   ANALYTICS & REPORTS — Premium SaaS Dashboard Theme
   -------------------------------------------------------------------------- */
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
  /* Brand */
  --gold:        #C89B3C;
  --gold-dim:    rgba(200,155,60,0.10);
  --gold-border: rgba(200,155,60,0.28);
  --green-dark:  #2E3A2D;
  --green-mid:   #3D5C3A;
  /* Palette */
  --slate-50:    #f8fafc;
  --slate-100:   #f1f5f9;
  --slate-200:   #e2e8f0;
  --slate-300:   #cbd5e1;
  --slate-400:   #94a3b8;
  --slate-500:   #64748b;
  --slate-600:   #475569;
  --slate-700:   #334155;
  --slate-800:   #1e293b;
  /* Semantic */
  --teal:        #0d9488;
  --teal-dim:    rgba(13,148,136,0.10);
  --blue:        #3b82f6;
  --blue-dim:    rgba(59,130,246,0.10);
  --amber:       #f59e0b;
  --amber-dim:   rgba(245,158,11,0.10);
  --emerald:     #10b981;
  --emerald-dim: rgba(16,185,129,0.10);
  --rose:        #f43f5e;
  --rose-dim:    rgba(244,63,94,0.10);
  /* Structure */
  --radius-sm:   8px;
  --radius-md:   12px;
  --radius-lg:   16px;
  --shadow-sm:   0 1px 3px rgba(0,0,0,0.07), 0 1px 2px rgba(0,0,0,0.04);
  --shadow-md:   0 4px 12px rgba(0,0,0,0.08), 0 2px 4px rgba(0,0,0,0.04);
  --shadow-lg:   0 10px 30px rgba(0,0,0,0.10), 0 4px 8px rgba(0,0,0,0.04);
}

/* --- Global --- */
.ar-page { font-family: 'Plus Jakarta Sans', sans-serif; color: var(--slate-700); }

/* --- Page Header --- */
.ar-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  flex-wrap: wrap; gap: 16px;
  padding: 24px 28px 20px;
  background: #fff;
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.ar-header::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--gold) 0%, var(--teal) 50%, var(--green-mid) 100%);
}
.ar-header-meta { display: flex; align-items: center; gap: 14px; }
.ar-header-icon {
  width: 48px; height: 48px; border-radius: var(--radius-md);
  background: var(--gold-dim); border: 1px solid var(--gold-border);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ar-header-icon i { font-size: 1.25rem; color: var(--gold); }
.ar-header-title { font-size: 1.35rem; font-weight: 800; color: var(--green-dark); margin: 0 0 3px; letter-spacing: -0.3px; }
.ar-header-sub { font-size: 0.8rem; color: var(--slate-400); margin: 0; }
.ar-header-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.ar-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; border-radius: var(--radius-sm);
  font-size: 0.82rem; font-weight: 700; text-decoration: none;
  transition: all 0.15s; cursor: pointer; border: none;
}
.ar-btn-gold {
  background: linear-gradient(135deg, var(--gold) 0%, #a07828 100%);
  color: #fff; box-shadow: 0 2px 8px rgba(200,155,60,0.35);
}
.ar-btn-gold:hover { opacity: 0.88; transform: translateY(-1px); color: #fff; box-shadow: 0 4px 14px rgba(200,155,60,0.40); }
.ar-btn-outline {
  background: #fff; color: var(--green-dark);
  border: 1.5px solid var(--slate-200);
}
.ar-btn-outline:hover { background: var(--slate-50); border-color: var(--gold); color: var(--gold); transform: translateY(-1px); }

/* --- KPI Grid --- */
.ar-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}
@media (max-width: 1100px) { .ar-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px)  { .ar-kpi-grid { grid-template-columns: 1fr; } }

.ar-kpi-card {
  background: #fff;
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  padding: 22px 22px 18px;
  box-shadow: var(--shadow-sm);
  position: relative; overflow: hidden;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  display: flex; flex-direction: column; gap: 10px;
}
.ar-kpi-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.ar-kpi-card-accent {
  position: absolute; top: 0; left: 0; width: 4px; bottom: 0;
  border-radius: var(--radius-lg) 0 0 var(--radius-lg);
}
.ar-kpi-top { display: flex; align-items: flex-start; justify-content: space-between; }
.ar-kpi-icon-ring {
  width: 44px; height: 44px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; flex-shrink: 0;
}
.ar-kpi-trend {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 0.72rem; font-weight: 700; padding: 3px 8px;
  border-radius: 20px;
}
.ar-kpi-number {
  font-size: 2.1rem; font-weight: 800; line-height: 1;
  color: var(--slate-800); letter-spacing: -1px;
}
.ar-kpi-label {
  font-size: 0.77rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.07em; color: var(--slate-400);
}
.ar-kpi-tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 2px; }
.ar-tag {
  font-size: 0.69rem; padding: 2px 8px; border-radius: 20px; font-weight: 600;
}
/* accent colours */
.accent-gold    { background: linear-gradient(135deg, var(--gold), #a07828); }
.accent-teal    { background: linear-gradient(135deg, var(--teal), #0f766e); }
.accent-blue    { background: linear-gradient(135deg, var(--blue), #2563eb); }
.accent-emerald { background: linear-gradient(135deg, var(--emerald), #059669); }
.ring-gold    { background: var(--gold-dim);    color: var(--gold); }
.ring-teal    { background: var(--teal-dim);    color: var(--teal); }
.ring-blue    { background: var(--blue-dim);    color: var(--blue); }
.ring-emerald { background: var(--emerald-dim); color: var(--emerald); }
.trend-up     { background: var(--emerald-dim); color: #065f46; }
.trend-down   { background: var(--rose-dim);    color: #9f1239; }
.trend-neutral{ background: var(--slate-100);   color: var(--slate-500); }
.tag-gold     { background: var(--gold-dim);    color: #7a5214; }
.tag-green    { background: var(--emerald-dim); color: #065f46; }
.tag-amber    { background: var(--amber-dim);   color: #92400e; }
.tag-red      { background: var(--rose-dim);    color: #9f1239; }
.tag-slate    { background: var(--slate-100);   color: var(--slate-600); }
.tag-blue     { background: var(--blue-dim);    color: #1d4ed8; }

/* --- Chart Grid --- */
.ar-chart-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
  margin-bottom: 24px;
}
@media (max-width: 900px) { .ar-chart-grid { grid-template-columns: 1fr; } }
.ar-chart-card {
  background: #fff;
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  padding: 22px 22px 18px;
  box-shadow: var(--shadow-sm);
  transition: box-shadow 0.2s;
}
.ar-chart-card:hover { box-shadow: var(--shadow-md); }
.ar-chart-full { grid-column: 1 / -1; }
.ar-chart-header { margin-bottom: 16px; }
.ar-chart-title {
  display: flex; align-items: center; gap: 8px;
  font-size: 0.85rem; font-weight: 700; color: var(--slate-700);
  margin-bottom: 3px;
}
.ar-chart-title-dot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.ar-chart-sub { font-size: 0.75rem; color: var(--slate-400); margin: 0; }
.ar-chart-canvas-wrap { position: relative; }
.ar-chart-canvas-wrap canvas { max-height: 270px; }
/* Donut center text */
.ar-donut-wrap { position: relative; display: inline-block; }
.ar-donut-center {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  text-align: center; pointer-events: none;
}
.ar-donut-center-num { font-size: 1.6rem; font-weight: 800; color: var(--slate-800); line-height: 1; }
.ar-donut-center-lbl { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--slate-400); }

/* --- Table Section --- */
.ar-table-section {
  background: #fff;
  border: 1px solid var(--slate-200);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  margin-bottom: 28px;
}
.ar-table-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
  padding: 18px 22px 16px;
  border-bottom: 1px solid var(--slate-100);
  background: var(--slate-50);
}
.ar-table-title {
  display: flex; align-items: center; gap: 9px;
  font-size: 0.87rem; font-weight: 700; color: var(--slate-700);
}
.ar-table-title-icon {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--gold-dim); display: flex; align-items: center; justify-content: center;
}
.ar-table-title-icon i { font-size: 0.85rem; color: var(--gold); }
.ar-table-count {
  font-size: 0.7rem; font-weight: 700; padding: 3px 9px;
  border-radius: 20px; background: var(--blue-dim); color: #1d4ed8;
}
/* Report type nav */
.ar-type-nav { display: flex; gap: 6px; flex-wrap: wrap; padding: 14px 22px 0; }
.ar-type-pill {
  padding: 5px 13px; border-radius: 20px; font-size: 0.76rem; font-weight: 600;
  text-decoration: none; border: 1.5px solid var(--slate-200);
  color: var(--slate-500); background: #fff;
  transition: all 0.15s;
}
.ar-type-pill:hover { border-color: var(--gold); color: var(--green-dark); background: var(--gold-dim); }
.ar-type-pill.active { background: var(--green-dark); color: #fff; border-color: var(--green-dark); }
/* Filter row */
.ar-filter-row {
  display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;
  padding: 14px 22px; border-bottom: 1px solid var(--slate-100);
}
.ar-filter-group label {
  display: block; font-size: 0.7rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--slate-400); margin-bottom: 5px;
}
.ar-filter-input {
  border: 1.5px solid var(--slate-200); border-radius: var(--radius-sm);
  padding: 7px 11px; font-size: 0.82rem; color: var(--slate-700);
  background: #fff; outline: none; transition: border-color 0.15s;
  font-family: inherit;
}
.ar-filter-input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px var(--gold-dim); }
.ar-btn-apply {
  background: var(--green-dark); color: #fff; border: none;
  border-radius: var(--radius-sm); padding: 8px 16px;
  font-size: 0.82rem; font-weight: 700; font-family: inherit;
  cursor: pointer; display: flex; align-items: center; gap: 6px;
  transition: background 0.15s;
}
.ar-btn-apply:hover { background: var(--green-mid); }
.ar-btn-reset {
  background: var(--slate-50); color: var(--slate-500);
  border: 1.5px solid var(--slate-200); border-radius: var(--radius-sm);
  padding: 7px 14px; font-size: 0.82rem; font-weight: 600;
  cursor: pointer; text-decoration: none; display: inline-flex;
  align-items: center; gap: 6px; transition: all 0.15s;
}
.ar-btn-reset:hover { background: var(--slate-100); color: var(--slate-700); }
/* Data table */
.ar-scroll-wrap {
  max-height: 440px; overflow: auto;
  scrollbar-width: thin; scrollbar-color: var(--gold) var(--slate-100);
}
.ar-scroll-wrap::-webkit-scrollbar { width: 5px; height: 5px; }
.ar-scroll-wrap::-webkit-scrollbar-track { background: var(--slate-100); }
.ar-scroll-wrap::-webkit-scrollbar-thumb { background: var(--gold); border-radius: 4px; }
.ar-data-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
.ar-data-table thead th {
  position: sticky; top: 0; z-index: 5;
  background: var(--slate-50); color: var(--slate-500);
  font-size: 0.71rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.06em; padding: 11px 16px;
  border-bottom: 1.5px solid var(--slate-200); white-space: nowrap;
}
.ar-data-table tbody tr { transition: background 0.1s; }
.ar-data-table tbody tr:hover { background: var(--slate-50); }
.ar-data-table tbody td { padding: 11px 16px; border-bottom: 1px solid var(--slate-100); color: var(--slate-600); vertical-align: middle; }
.ar-data-table tbody tr:last-child td { border-bottom: none; }
/* Status pills */
.s-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 10px; border-radius: 20px; font-size: 0.71rem; font-weight: 700;
}
.s-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: 0.7; }
.s-pill.pending, .s-pill.submitted { background: #fef9c3; color: #854d0e; }
.s-pill.approved { background: #dcfce7; color: #166534; }
.s-pill.completed, .s-pill.released { background: #dbeafe; color: #1e40af; }
.s-pill.rejected, .s-pill.cancelled { background: #ffe4e6; color: #9f1239; }
.s-pill.in_processing, .s-pill.processing, .s-pill.in-processing { background: #e0f2fe; color: #0369a1; }
.s-pill.requirements_review { background: #f3e8ff; color: #6d28d9; }
/* Pagination */
.ar-pagination { padding: 14px 22px; border-top: 1px solid var(--slate-100); }
.ar-pagination .page-link { border-radius: var(--radius-sm) !important; font-size: 0.82rem; font-weight: 600; }
.ar-pagination .page-item.active .page-link { background: var(--green-dark); border-color: var(--green-dark); }
/* Empty state */
.ar-empty { text-align: center; padding: 48px 24px; color: var(--slate-400); }
.ar-empty i { font-size: 2.2rem; display: block; margin-bottom: 12px; opacity: 0.4; }
.ar-empty strong { display: block; color: var(--slate-600); font-size: 0.9rem; margin-bottom: 4px; }
.ar-empty span { font-size: 0.8rem; }
</style>

<?php
// Compute completion rate for KPI trend badge
$kpi_completion_rate = $kpi_requests_total > 0
    ? round(($kpi_requests_total - $kpi_requests_pending) / $kpi_requests_total * 100)
    : 0;
?>

<div class="ar-page container-fluid px-0">

  <!-- === PAGE HEADER === -->
  <div class="ar-header">
    <div class="ar-header-meta">
      <div class="ar-header-icon"><i class="fas fa-chart-line"></i></div>
      <div>
        <h1 class="ar-header-title">Analytics &amp; Reports</h1>
        <p class="ar-header-sub">Parish operational statistics &mdash; <?php echo date('F Y'); ?></p>
      </div>
    </div>
    <?php if (hasPermission('reports.export')): ?>
    <div class="ar-header-actions">
      <a class="ar-btn ar-btn-gold" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'pdf']))); ?>">
        <i class="fas fa-file-pdf"></i> Export PDF
      </a>
      <a class="ar-btn ar-btn-outline" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'csv']))); ?>">
        <i class="fas fa-file-csv"></i> Export CSV
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- === KPI CARDS === -->
  <div class="ar-kpi-grid">

    <!-- Parishioners -->
    <div class="ar-kpi-card">
      <div class="ar-kpi-card-accent accent-gold"></div>
      <div class="ar-kpi-top">
        <div class="ar-kpi-icon-ring ring-gold"><i class="fas fa-users"></i></div>
        <span class="ar-kpi-trend trend-up"><i class="fas fa-arrow-up" style="font-size:.6em;"></i> Active</span>
      </div>
      <div class="ar-kpi-number"><?php echo number_format($kpi_parishioners_total); ?></div>
      <div class="ar-kpi-label">Registered Parishioners</div>
      <div class="ar-kpi-tags">
        <span class="ar-tag tag-green"><i class="fas fa-circle-check" style="font-size:.65em;"></i> <?php echo number_format($kpi_parishioners_verified); ?> Verified</span>
        <?php if ($kpi_parishioners_pending > 0): ?>
        <span class="ar-tag tag-amber"><?php echo number_format($kpi_parishioners_pending); ?> Pending</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Sacramental Records -->
    <div class="ar-kpi-card">
      <div class="ar-kpi-card-accent accent-teal"></div>
      <div class="ar-kpi-top">
        <div class="ar-kpi-icon-ring ring-teal"><i class="fas fa-book-bible"></i></div>
        <span class="ar-kpi-trend trend-neutral"><i class="fas fa-minus" style="font-size:.6em;"></i> All time</span>
      </div>
      <div class="ar-kpi-number"><?php echo number_format($kpi_sacraments_total); ?></div>
      <div class="ar-kpi-label">Sacramental Records</div>
      <div class="ar-kpi-tags">
        <?php foreach ($sacrament_counts as $type => $cnt): ?>
        <span class="ar-tag tag-gold"><?php echo $cnt; ?> <?php echo $type; ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Requests -->
    <div class="ar-kpi-card">
      <div class="ar-kpi-card-accent accent-blue"></div>
      <div class="ar-kpi-top">
        <div class="ar-kpi-icon-ring ring-blue"><i class="fas fa-inbox"></i></div>
        <?php if ($kpi_requests_pending > 0): ?>
        <span class="ar-kpi-trend trend-down"><i class="fas fa-clock" style="font-size:.65em;"></i> <?php echo $kpi_requests_pending; ?> pending</span>
        <?php else: ?>
        <span class="ar-kpi-trend trend-up"><i class="fas fa-check" style="font-size:.65em;"></i> All clear</span>
        <?php endif; ?>
      </div>
      <div class="ar-kpi-number"><?php echo number_format($kpi_requests_total); ?></div>
      <div class="ar-kpi-label">Service &amp; Certificate Requests</div>
      <div class="ar-kpi-tags">
        <span class="ar-tag tag-blue"><?php echo $kpi_completion_rate; ?>% Completion Rate</span>
        <?php if ($kpi_requests_pending > 0): ?>
        <span class="ar-tag tag-red"><?php echo $kpi_requests_pending; ?> Need Action</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Calendar Events -->
    <div class="ar-kpi-card">
      <div class="ar-kpi-card-accent accent-emerald"></div>
      <div class="ar-kpi-top">
        <div class="ar-kpi-icon-ring ring-emerald"><i class="fas fa-calendar-days"></i></div>
        <span class="ar-kpi-trend trend-neutral"><i class="fas fa-calendar" style="font-size:.65em;"></i> This month</span>
      </div>
      <div class="ar-kpi-number"><?php echo number_format($kpi_events_month); ?></div>
      <div class="ar-kpi-label">Scheduled Events</div>
      <div class="ar-kpi-tags">
        <span class="ar-tag tag-slate"><?php echo date('F Y'); ?></span>
      </div>
    </div>

  </div><!-- /kpi-grid -->

  <!-- === CHARTS === -->
  <div class="ar-chart-grid">

    <!-- Chart A: Grouped Bar - full width -->
    <div class="ar-chart-card ar-chart-full">
      <div class="ar-chart-header">
        <div class="ar-chart-title">
          <span class="ar-chart-title-dot" style="background:var(--gold);"></span>
          Sacramental Records Administered
        </div>
        <p class="ar-chart-sub">Monthly volume by sacrament type &ndash; last 12 months</p>
      </div>
      <div class="ar-chart-canvas-wrap">
        <canvas id="chartSacraments" role="img" aria-label="Sacramental records by month"></canvas>
      </div>
    </div>

    <!-- Chart B: Donut -->
    <div class="ar-chart-card">
      <div class="ar-chart-header">
        <div class="ar-chart-title">
          <span class="ar-chart-title-dot" style="background:var(--blue);"></span>
          Request Status Breakdown
        </div>
        <p class="ar-chart-sub">Distribution of all service requests by current status</p>
      </div>
      <div style="display:flex;justify-content:center;align-items:center;padding:8px 0;">
        <div class="ar-donut-wrap">
          <canvas id="chartRequestStatus" role="img" aria-label="Request status breakdown" width="260" height="260"></canvas>
          <div class="ar-donut-center">
            <div class="ar-donut-center-num"><?php echo number_format($kpi_requests_total); ?></div>
            <div class="ar-donut-center-lbl">Total</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Chart C: Horizontal Bar -->
    <div class="ar-chart-card">
      <div class="ar-chart-header">
        <div class="ar-chart-title">
          <span class="ar-chart-title-dot" style="background:var(--teal);"></span>
          Most Requested Services
        </div>
        <p class="ar-chart-sub">Top 8 service &amp; certificate types by total volume</p>
      </div>
      <div class="ar-chart-canvas-wrap">
        <canvas id="chartTopServices" role="img" aria-label="Top requested service types"></canvas>
      </div>
    </div>

    <!-- Chart D: Area Line - full width -->
    <div class="ar-chart-card ar-chart-full">
      <div class="ar-chart-header">
        <div class="ar-chart-title">
          <span class="ar-chart-title-dot" style="background:var(--emerald);"></span>
          Parishioner Registration Growth
        </div>
        <p class="ar-chart-sub">Newly registered parishioners per month &ndash; last 12 months</p>
      </div>
      <div class="ar-chart-canvas-wrap">
        <canvas id="chartParishGrowth" role="img" aria-label="Parishioner registration growth"></canvas>
      </div>
    </div>

  </div><!-- /chart-grid -->

  <!-- === MASTER RECORDS TABLE === -->
  <div class="ar-table-section">

    <div class="ar-table-header">
      <div class="ar-table-title">
        <div class="ar-table-title-icon"><i class="fas fa-table-list"></i></div>
        All Parish Records &amp; Activity Log
        <span class="ar-table-count"><?php echo number_format($tableData['total']); ?> records</span>
      </div>
      <?php if (hasPermission('reports.export')): ?>
      <div style="display:flex;gap:8px;">
        <a class="ar-btn ar-btn-gold" style="padding:7px 14px;font-size:0.78rem;" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'pdf']))); ?>">
          <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a class="ar-btn ar-btn-outline" style="padding:7px 14px;font-size:0.78rem;" href="?<?php echo e(http_build_query(array_merge($queryBase, ['export' => 'csv']))); ?>">
          <i class="fas fa-file-csv"></i> CSV
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Report Type Pills -->
    <div class="ar-type-nav">
      <?php foreach ($labels as $key => $label): ?>
      <a class="ar-type-pill <?php echo $report === $key ? 'active' : ''; ?>" href="?report=<?php echo e($key); ?>"><?php echo e($label); ?></a>
      <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="get" class="ar-filter-row">
      <input type="hidden" name="report" value="<?php echo e($report); ?>">
      <div class="ar-filter-group">
        <label for="filterFrom">From</label>
        <input id="filterFrom" class="ar-filter-input" type="date" name="from" value="<?php echo e($filters['from']); ?>">
      </div>
      <div class="ar-filter-group">
        <label for="filterTo">To</label>
        <input id="filterTo" class="ar-filter-input" type="date" name="to" value="<?php echo e($filters['to']); ?>">
      </div>
      <div class="ar-filter-group">
        <label for="filterStatus">Status</label>
        <select id="filterStatus" class="ar-filter-input" name="status">
          <option value="">All Statuses</option>
          <?php foreach (['pending','submitted','approved','in_processing','completed','released','rejected','cancelled'] as $st): ?>
          <option value="<?php echo $st; ?>" <?php echo $filters['status'] === $st ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_',' ',$st)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ar-filter-group">
        <label for="filterType">Type</label>
        <input id="filterType" class="ar-filter-input" type="text" name="type" value="<?php echo e($filters['type']); ?>" placeholder="e.g. baptismal_cert" style="width:170px;">
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <button type="submit" class="ar-btn-apply"><i class="fas fa-filter"></i> Apply</button>
        <a class="ar-btn-reset" href="?report=<?php echo e($report); ?>"><i class="fas fa-rotate-left"></i> Reset</a>
      </div>
    </form>

    <?php if ($tableData['truncated']): ?>
    <div style="padding:10px 22px;">
      <div class="alert alert-warning py-2 mb-0" role="alert" style="font-size:0.8rem;">
        Showing first <?php echo number_format($tableData['limit']); ?> records. Total: <?php echo number_format($tableData['total']); ?>.
      </div>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="ar-scroll-wrap">
      <table class="ar-data-table">
        <thead>
          <tr>
            <?php foreach ($tableData['columns'] as $colLabel): ?>
            <th scope="col"><?php echo e($colLabel); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tableData['rows']): ?>
          <tr><td colspan="<?php echo count($tableData['columns']); ?>">
            <div class="ar-empty">
              <i class="fas fa-folder-open"></i>
              <strong>No records found</strong>
              <span>Try widening your date range or clearing a filter.</span>
            </div>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($tableData['rows'] as $tRow): ?>
          <tr>
            <?php foreach (array_keys($tableData['columns']) as $colKey): ?>
            <?php
              $cellVal = (string)($tRow[$colKey] ?? '–');
              if ($colKey === 'status' && $cellVal !== '–') {
                  $slug = strtolower(str_replace([' ','-'], '_', $cellVal));
                  echo '<td><span class="s-pill ' . e($slug) . '">' . e($cellVal) . '</span></td>';
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

    <!-- Pagination -->
    <?php if ($tableData['pages'] > 1): ?>
    <div class="ar-pagination">
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

  </div><!-- /table-section -->

</div><!-- /ar-page -->

<!-- === CHART.JS & HIGH-RES PDF EXPORT SCRIPTS === -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
(function () {
  'use strict';

  // Global registry for chart instances
  window.parishCharts = {
    sacraments: null,
    requestStatus: null,
    topServices: null,
    parishGrowth: null
  };

  /* --- Design Tokens --- */
  const GOLD     = '#C89B3C';
  const TEAL     = '#0d9488';
  const BLUE     = '#3b82f6';
  const EMERALD  = '#10b981';
  const ROSE     = '#f43f5e';
  const AMBER    = '#f59e0b';
  const SLATE    = '#64748b';

  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.color = '#64748b';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.titleFont = { weight: '700', size: 12 };

  /* --- Sacrament color palette --- */
  const sacramentPalette = [
    { border: GOLD,      bg: 'rgba(200,155,60,0.80)'   },
    { border: TEAL,      bg: 'rgba(13,148,136,0.80)'   },
    { border: BLUE,      bg: 'rgba(59,130,246,0.80)'   },
    { border: EMERALD,   bg: 'rgba(16,185,129,0.80)'   },
    { border: '#8b5cf6', bg: 'rgba(139,92,246,0.80)' },
  ];

  /* --- Chart A: Sacramental Records Administered --- */
  const chartAEl = document.getElementById('chartSacraments');
  if (chartAEl) {
    const rawDatasets = <?php echo json_encode($chart_a_datasets); ?>;
    const styledDatasets = rawDatasets.map((ds, i) => ({
      ...ds,
      backgroundColor: sacramentPalette[i % sacramentPalette.length].bg,
      borderColor:     sacramentPalette[i % sacramentPalette.length].border,
      borderWidth: 0,
      borderRadius: 6,
      borderSkipped: false,
      hoverBackgroundColor: sacramentPalette[i % sacramentPalette.length].border,
    }));
    window.parishCharts.sacraments = new Chart(chartAEl, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($chart_a_labels); ?>,
        datasets: styledDatasets
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        animation: { duration: 400 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            position: 'bottom',
            labels: { padding: 20, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', font: { size: 12, weight: '600' } }
          },
          tooltip: {
            callbacks: {
              label: ctx => '  ' + ctx.dataset.label + ': ' + ctx.parsed.y + ' records'
            }
          }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 11, weight: '500' } } },
          y: { beginAtZero: true, grid: { color: '#f1f5f9', lineWidth: 1 }, border: { display: false, dash: [4,4] }, ticks: { precision: 0, font: { size: 11 }, padding: 8 } }
        }
      }
    });
  }

  /* --- Chart B: Request Status Breakdown (Donut) --- */
  const chartBEl = document.getElementById('chartRequestStatus');
  if (chartBEl) {
    window.parishCharts.requestStatus = new Chart(chartBEl, {
      type: 'doughnut',
      data: {
        labels: <?php echo json_encode($chart_b_labels); ?>,
        datasets: [{
          data: <?php echo json_encode($chart_b_data); ?>,
          backgroundColor: [AMBER, BLUE, EMERALD, ROSE],
          borderWidth: 3,
          borderColor: '#ffffff',
          hoverBorderWidth: 0,
          hoverOffset: 10,
          borderRadius: 4,
        }]
      },
      options: {
        responsive: false,
        cutout: '72%',
        animation: { duration: 400 },
        plugins: {
          legend: {
            position: 'right',
            labels: { padding: 16, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', font: { size: 12, weight: '600' } }
          },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                const pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                return '  ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
              }
            }
          }
        }
      }
    });
  }

  /* --- Chart C: Top Requested Services (Horizontal Bar) --- */
  const chartCEl = document.getElementById('chartTopServices');
  if (chartCEl) {
    const topLabels = <?php echo json_encode($chart_c_labels); ?>;
    const topData   = <?php echo json_encode($chart_c_data); ?>;
    const ramp = [TEAL,'#14b8a6','#22d3ee',BLUE,'#60a5fa','#818cf8','#a78bfa',GOLD];
    window.parishCharts.topServices = new Chart(chartCEl, {
      type: 'bar',
      data: {
        labels: topLabels,
        datasets: [{
          label: 'Requests',
          data: topData,
          backgroundColor: topData.map((_, i) => ramp[i % ramp.length] + 'CC'),
          borderColor: topData.map((_, i) => ramp[i % ramp.length]),
          borderWidth: 0,
          borderRadius: 6,
          borderSkipped: false,
          hoverBackgroundColor: topData.map((_, i) => ramp[i % ramp.length]),
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: true,
        animation: { duration: 400 },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => '  ' + ctx.parsed.x + ' requests' } }
        },
        scales: {
          x: { beginAtZero: true, grid: { color: '#f1f5f9' }, border: { display: false }, ticks: { precision: 0, font: { size: 11 } } },
          y: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 11, weight: '600' }, padding: 6 } }
        }
      }
    });
  }

  /* --- Chart D: Parishioner Registration Growth (Area Line) --- */
  const chartDEl = document.getElementById('chartParishGrowth');
  if (chartDEl) {
    const gradientFill = (context) => {
      const { ctx, chartArea } = context.chart;
      if (!chartArea) return 'transparent';
      const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
      gradient.addColorStop(0,   'rgba(16,185,129,0.28)');
      gradient.addColorStop(0.6, 'rgba(16,185,129,0.08)');
      gradient.addColorStop(1,   'rgba(16,185,129,0.00)');
      return gradient;
    };
    window.parishCharts.parishGrowth = new Chart(chartDEl, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($chart_a_labels); ?>,
        datasets: [{
          label: 'New Parishioners',
          data: <?php echo json_encode(array_values($chart_d_data)); ?>,
          borderColor: EMERALD,
          borderWidth: 2.5,
          backgroundColor: gradientFill,
          fill: true,
          tension: 0.45,
          pointBackgroundColor: EMERALD,
          pointBorderColor: '#ffffff',
          pointBorderWidth: 2.5,
          pointRadius: 5,
          pointHoverRadius: 8,
          pointHoverBackgroundColor: EMERALD,
          pointHoverBorderWidth: 3,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        animation: { duration: 400 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => '  ' + ctx.parsed.y + ' new registrations' } }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 11, weight: '500' } } },
          y: { beginAtZero: true, grid: { color: '#f1f5f9' }, border: { display: false }, ticks: { precision: 0, font: { size: 11 }, padding: 8 } }
        }
      }
    });
  }

  /* --- High-Resolution Chart Image Capture Helper --- */
  function extractChartBase64(inst, canvasId) {
    if (inst) {
      try {
        if (typeof inst.update === 'function') inst.update('none');
        if (typeof inst.toBase64Image === 'function') {
          const img = inst.toBase64Image('image/png', 1.0);
          if (img && img.startsWith('data:image/')) return img;
        }
      } catch (e) {
        console.warn('chartInstance.toBase64Image error:', e);
      }
    }
    const canvas = document.getElementById(canvasId);
    if (canvas && typeof canvas.toDataURL === 'function') {
      try {
        return canvas.toDataURL('image/png', 1.0);
      } catch (e) {
        console.warn('canvas.toDataURL error:', e);
      }
    }
    return '';
  }

  /* --- PDF Export with Chart Capture & Form POST --- */
  function triggerPdfExportWithCharts(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();
    const btn = e ? e.currentTarget : null;
    let origHtml = '';
    if (btn) {
      origHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing PDF...';
    }

    try {
      const sacramentsImg    = extractChartBase64(window.parishCharts.sacraments, 'chartSacraments');
      const requestStatusImg = extractChartBase64(window.parishCharts.requestStatus, 'chartRequestStatus');
      const topServicesImg   = extractChartBase64(window.parishCharts.topServices, 'chartTopServices');
      const parishGrowthImg  = extractChartBase64(window.parishCharts.parishGrowth, 'chartParishGrowth');

      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'reports.php';
      form.style.display = 'none';

      const postParams = {
        'export': 'pdf',
        'report': <?php echo json_encode($report); ?>,
        'from': <?php echo json_encode($filters['from']); ?>,
        'to': <?php echo json_encode($filters['to']); ?>,
        'status': <?php echo json_encode($filters['status']); ?>,
        'type': <?php echo json_encode($filters['type']); ?>,
        'chart_sacraments': sacramentsImg,
        'chart_request_status': requestStatusImg,
        'chart_top_services': topServicesImg,
        'chart_parish_growth': parishGrowthImg,
        <?php echo json_encode(csrfTokenName()); ?>: <?php echo json_encode(generateCsrfToken()); ?>
      };

      for (const [key, val] of Object.entries(postParams)) {
        if (val !== undefined && val !== null) {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = val;
          form.appendChild(input);
        }
      }

      document.body.appendChild(form);
      form.submit();

      setTimeout(() => {
        if (form.parentNode) form.parentNode.removeChild(form);
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = origHtml;
        }
      }, 3500);
    } catch (err) {
      console.error('PDF export with charts failed, falling back to direct stream:', err);
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = origHtml;
      }
      window.location.href = '?export=pdf&report=<?php echo urlencode($report); ?>';
    }
  }

  // Attach listener to PDF export buttons
  document.querySelectorAll('.ar-export-pdf-btn, a[href*="export=pdf"]').forEach(el => {
    el.addEventListener('click', triggerPdfExportWithCharts);
  });

})();
</script>

<?php include '../templates/footer.php'; ?>
