<?php
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../services/AuditLogService.php';
require_once '../includes/audit.php';

requireLogin();
requirePermission('audit.view');
$page_title = 'Audit Logs';

$date = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
$filters = [
    'q' => trim(mb_strimwidth((string)($_GET['q'] ?? ''), 0, 100, '')),
    'from' => $date($_GET['from'] ?? ''),
    'to' => $date($_GET['to'] ?? ''),
    'category' => preg_match('/^[A-Z_]{2,20}$/', (string)($_GET['category'] ?? '')) ? (string)$_GET['category'] : '',
    'severity' => in_array($_GET['severity'] ?? '', ['INFO', 'WARNING', 'CRITICAL'], true) ? (string)$_GET['severity'] : '',
    'actor' => trim(mb_strimwidth((string)($_GET['actor'] ?? ''), 0, 80, '')),
    'component' => preg_match('/^[a-z0-9._-]{0,80}$/i', (string)($_GET['component'] ?? '')) ? (string)($_GET['component'] ?? '') : ''
];

$service = new AuditLogService($conn);
$metrics = $service->getSummaryMetrics();

$export = $_GET['export'] ?? '';
if (in_array($export, ['csv', 'pdf'], true)) {
    requirePermission('audit.export');
    $rows = $service->exportRows($filters);
    writeAuditLog(
        $conn,
        $_SESSION['user_id'],
        'EXPORT_AUDIT_LOG',
        'audit_log',
        null,
        null,
        ['filters' => $filters, 'rows' => count($rows), 'format' => $export],
        'audit',
        'audit.export',
        null,
        'Exported ' . count($rows) . ' audit records in ' . strtoupper($export) . ' format.',
        'SYSTEM',
        'INFO'
    );

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tugon-audit-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Time',
            'Actor Name',
            'Actor Role',
            'Severity',
            'Category',
            'Action',
            'Target Type',
            'Target ID',
            'Summary Description',
            'Client IP',
            'Device / Browser',
            'Correlation ID'
        ]);
        foreach ($rows as $r) {
            $uaParsed = parseUserAgentSummary($r['user_agent'] ?? '');
            fputcsv($out, [
                $r['created_at'],
                $r['actor'],
                $r['actor_role'],
                $r['severity'],
                $r['event_category'],
                $r['action'],
                $r['target_type'],
                $r['target_id'] ?: '',
                $r['description'],
                $r['ip_address'],
                $uaParsed['browser'] . ' / ' . $uaParsed['device'],
                $r['correlation_id']
            ]);
        }
        fclose($out);
        exit;
    }

    // PDF export
    require_once '../vendor/autoload.php';
    require_once '../services/ReportPdfGenerator.php';
    $pdfColumns = [
        'created_at' => 'Time',
        'actor' => 'Actor',
        'severity' => 'Severity',
        'event_category' => 'Category',
        'action' => 'Action',
        'target_type' => 'Target',
        'description' => 'Summary',
        'ip_address' => 'IP'
    ];
    $pdfRows = array_map(static fn($r) => array_intersect_key($r, array_flip(array_keys($pdfColumns))), $rows);
    $pdfData = [
        'columns' => $pdfColumns,
        'rows' => $pdfRows,
        'total' => count($pdfRows),
        'truncated' => count($pdfRows) >= 10000
    ];
    $generatedBy = !empty($_SESSION['fullname']) ? (string)$_SESSION['fullname'] : 'Parish Administrator';
    $pdfFilters = [
        'from' => $filters['from'],
        'to' => $filters['to'],
        'status' => '',
        'type' => $filters['category'] ?: ($filters['component'] ?: ''),
        'q' => $filters['q']
    ];
    ReportPdfGenerator::stream(
        'audit_log',
        'Audit Log Report',
        $pdfFilters,
        $pdfData,
        $generatedBy,
        'landscape',
        'Security, Transactions & System Activity Logs'
    );
    exit;
}

$data = $service->page($filters, max(1, (int)($_GET['page'] ?? 1)), 50);
$base = array_filter($filters, static fn($v) => $v !== '');

include '../templates/header.php';
?>

<style>
  .audit-stat-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }
  .audit-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
  }
  .audit-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.1;
    color: #0f172a;
  }
  .audit-stat-label {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-bottom: 0.25rem;
  }
  .audit-stat-sub {
    font-size: 0.76rem;
    color: #94a3b8;
  }
  .audit-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
  }
  .stat-icon-blue { background: #eff6ff; color: #2563eb; }
  .stat-icon-green { background: #f0fdf4; color: #16a34a; }
  .stat-icon-amber { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
  .stat-icon-red { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

  .filter-panel-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
  }

  .log-panel-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    overflow: hidden;
  }
  .log-panel-header {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    padding: 1rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .logs-scroll-container {
    max-height: 620px;
    overflow-y: auto;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: #94a3b8 #f1f5f9;
  }
  .logs-scroll-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
  }
  .logs-scroll-container::-webkit-scrollbar-track {
    background: #f1f5f9;
  }
  .logs-scroll-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
  }
  .logs-scroll-container::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
  }
  .log-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8fafc;
    color: #475569;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 14px;
    white-space: nowrap;
  }
  .log-table td {
    font-size: 0.84rem;
    vertical-align: middle;
    padding: 12px 14px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
  }

  .severity-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    padding: 3px 8px;
    border-radius: 6px;
    text-transform: uppercase;
  }
  .severity-info { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
  .severity-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
  .severity-critical { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

  .category-tag {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 4px;
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  }

  .device-hint {
    font-size: 0.75rem;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  .diff-table th, .diff-table td {
    font-size: 0.82rem;
    padding: 8px 10px;
  }
  .diff-old {
    background-color: #fff1f2;
    color: #be123c;
    text-decoration: line-through;
    word-break: break-word;
  }
  .diff-new {
    background-color: #f0fdf4;
    color: #15803d;
    font-weight: 600;
    word-break: break-word;
  }
</style>

<div class="container-fluid px-0 audit-page">
  <!-- Standardized Section Header -->
  <?php
  $page_header_title = 'Audit Logs';
  $page_header_subtitle = 'Review system activity, security events, and canonical record accountability.';
  $page_header_icon = 'fa-shield-halved';
  $show_back_button = true;
  $back_button_url = BASE_URL . 'admin/dashboard.php';
  include '../includes/page_header.php';
  ?>

  <!-- 1. Top Summary Metric Cards -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="audit-stat-card">
        <div>
          <div class="audit-stat-label">Events Today</div>
          <div class="audit-stat-value"><?php echo number_format($metrics['total_today']); ?></div>
          <div class="audit-stat-sub">Logged system activities</div>
        </div>
        <div class="audit-stat-icon stat-icon-blue">
          <i class="fas fa-calendar-day"></i>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="audit-stat-card">
        <div>
          <div class="audit-stat-label">Admin Actions (7D)</div>
          <div class="audit-stat-value"><?php echo number_format($metrics['admin_actions_7d']); ?></div>
          <div class="audit-stat-sub">Staff approvals & modifications</div>
        </div>
        <div class="audit-stat-icon stat-icon-green">
          <i class="fas fa-user-shield"></i>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="audit-stat-card">
        <div>
          <div class="audit-stat-label text-warning-emphasis">Failed Logins</div>
          <div class="audit-stat-value text-warning-emphasis"><?php echo number_format($metrics['failed_logins']); ?></div>
          <div class="audit-stat-sub">Auth warnings & OTP failures</div>
        </div>
        <div class="audit-stat-icon stat-icon-amber">
          <i class="fas fa-triangle-exclamation"></i>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-xl-3">
      <div class="audit-stat-card">
        <div>
          <div class="audit-stat-label text-danger">Critical Events</div>
          <div class="audit-stat-value text-danger"><?php echo number_format($metrics['critical_events']); ?></div>
          <div class="audit-stat-sub">Archived records & deletions</div>
        </div>
        <div class="audit-stat-icon stat-icon-red">
          <i class="fas fa-shield-cat"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- 2. Enhanced Search & Filter Bar -->
  <form class="filter-panel-card" method="get">
    <div class="row g-3 align-items-end">
      <div class="col-lg-3 col-md-6">
        <label class="form-label fw-semibold small text-secondary" for="auditQ">Search</label>
        <div class="input-group">
          <span class="input-group-text bg-white text-muted border-end-0"><i class="fas fa-magnifying-glass"></i></span>
          <input id="auditQ" class="form-control border-start-0 ps-0" type="search" name="q" value="<?php echo e($filters['q']); ?>" placeholder="Action, actor, target, summary, IP...">
        </div>
      </div>

      <div class="col-lg-2 col-md-3 col-sm-6">
        <label class="form-label fw-semibold small text-secondary" for="auditCategory">Module / Category</label>
        <select id="auditCategory" class="form-select" name="category">
          <option value="">All Modules</option>
          <option value="AUTH" <?php echo $filters['category'] === 'AUTH' ? 'selected' : ''; ?>>Authentication (AUTH)</option>
          <option value="SACRAMENTS" <?php echo $filters['category'] === 'SACRAMENTS' ? 'selected' : ''; ?>>Sacramental Records</option>
          <option value="REQUESTS" <?php echo $filters['category'] === 'REQUESTS' ? 'selected' : ''; ?>>Service Requests</option>
          <option value="ACCOUNTS" <?php echo $filters['category'] === 'ACCOUNTS' ? 'selected' : ''; ?>>Accounts & Users</option>
          <option value="CERTIFICATES" <?php echo $filters['category'] === 'CERTIFICATES' ? 'selected' : ''; ?>>Certificates</option>
          <option value="SYSTEM" <?php echo $filters['category'] === 'SYSTEM' ? 'selected' : ''; ?>>System / Other</option>
        </select>
      </div>

      <div class="col-lg-2 col-md-3 col-sm-6">
        <label class="form-label fw-semibold small text-secondary" for="auditSeverity">Severity Level</label>
        <select id="auditSeverity" class="form-select" name="severity">
          <option value="">All Severities</option>
          <option value="INFO" <?php echo $filters['severity'] === 'INFO' ? 'selected' : ''; ?>>[INFO] Normal Activity</option>
          <option value="WARNING" <?php echo $filters['severity'] === 'WARNING' ? 'selected' : ''; ?>>[WARNING] Warnings & Failures</option>
          <option value="CRITICAL" <?php echo $filters['severity'] === 'CRITICAL' ? 'selected' : ''; ?>>[CRITICAL] Critical & Archived</option>
        </select>
      </div>

      <div class="col-lg-2 col-md-4 col-sm-6">
        <label class="form-label fw-semibold small text-secondary" for="auditFrom">From</label>
        <input id="auditFrom" class="form-control" type="date" name="from" value="<?php echo e($filters['from']); ?>">
      </div>

      <div class="col-lg-2 col-md-4 col-sm-6">
        <label class="form-label fw-semibold small text-secondary" for="auditTo">To</label>
        <input id="auditTo" class="form-control" type="date" name="to" value="<?php echo e($filters['to']); ?>">
      </div>

      <div class="col-lg-1 col-md-4 d-flex gap-2">
        <button class="btn btn-primary w-100 fw-semibold" type="submit" title="Apply Filters">
          <i class="fas fa-filter"></i>
        </button>
        <?php if(!empty($base)): ?>
          <a class="btn btn-outline-secondary" href="audit-logs.php" title="Reset Filters">
            <i class="fas fa-rotate-left"></i>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <!-- Export & Record Count Bar -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-secondary small" role="status">
      Found <strong class="text-dark"><?php echo number_format($data['total']); ?></strong> matching events &bull; Page <strong><?php echo $data['page']; ?></strong> of <strong><?php echo $data['pages']; ?></strong>
    </div>
    <?php if(hasPermission('audit.export')): ?>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-success fw-semibold" href="?<?php echo e(http_build_query(array_merge($base, ['export' => 'csv']))); ?>">
          <i class="fas fa-file-csv me-1" aria-hidden="true"></i> Export CSV
        </a>
        <a class="btn btn-sm btn-outline-danger fw-semibold" href="?<?php echo e(http_build_query(array_merge($base, ['export' => 'pdf']))); ?>">
          <i class="fas fa-file-pdf me-1" aria-hidden="true"></i> Export PDF
        </a>
      </div>
    <?php endif; ?>
  </div>

  <?php if($data['truncated']): ?>
    <div class="alert alert-warning py-2 mb-3 small">
      <i class="fas fa-circle-exclamation me-1"></i> Result sets are capped at <?php echo number_format($data['limit']); ?> records for performance. Please narrow your date range or search terms.
    </div>
  <?php endif; ?>

  <!-- 3. Upgraded Audit Events Table -->
  <section class="card log-panel-card mb-4">
    <div class="card-header log-panel-header">
      <div class="d-flex align-items-center gap-2">
        <h2 class="h6 mb-0 fw-bold text-dark"><i class="fas fa-list-check me-2 text-primary"></i>Audit Trail Records</h2>
        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1" style="font-size: 0.74rem;">
          <?php echo number_format($data['total']); ?> records
        </span>
      </div>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive logs-scroll-container">
        <table class="table table-hover align-middle mb-0 log-table">
          <thead>
            <tr>
              <th style="min-width: 140px;">Time</th>
              <th style="min-width: 160px;">Actor</th>
              <th style="min-width: 140px;">IP / Device</th>
              <th style="min-width: 180px;">Event & Severity</th>
              <th style="min-width: 160px;">Target Entity</th>
              <th style="min-width: 260px;">Summary</th>
              <th style="min-width: 110px;" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$data['rows']): ?>
              <tr>
                <td colspan="7" class="text-center py-5">
                  <div class="text-muted mb-1"><i class="fas fa-folder-open fa-2x"></i></div>
                  <strong>No audit events found.</strong>
                  <div class="text-muted small">Try clearing filters or selecting another date range.</div>
                </td>
              </tr>
            <?php endif; ?>

            <?php foreach($data['rows'] as $row): 
              $ua = parseUserAgentSummary($row['user_agent'] ?? '');
              $sev = strtoupper($row['severity'] ?? 'INFO');
              $sevClass = match($sev) {
                  'CRITICAL' => 'severity-critical',
                  'WARNING' => 'severity-warning',
                  default => 'severity-info',
              };
              $sevIcon = match($sev) {
                  'CRITICAL' => 'fa-shield-halved',
                  'WARNING' => 'fa-triangle-exclamation',
                  default => 'fa-circle-info',
              };
              $actorRole = strtolower($row['actor_role'] ?? 'user');
              $roleBadgeClass = match($actorRole) {
                  'admin', 'administrator' => 'bg-primary-subtle text-primary border border-primary-subtle',
                  'staff', 'parish_staff', 'secretary', 'clerk', 'records_clerk' => 'bg-success-subtle text-success border border-success-subtle',
                  'priest' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                  'system' => 'bg-secondary-subtle text-secondary border',
                  default => 'bg-light text-secondary border',
              };
              $cleanAction = ucwords(strtolower(str_replace('_', ' ', $row['action'])));
              $hasDiff = (!empty($row['old_values']) && $row['old_values'] !== '{}' && $row['old_values'] !== '[]') 
                      || (!empty($row['new_values']) && $row['new_values'] !== '{}' && $row['new_values'] !== '[]');
              
              // Prepare JSON payload for the View Details modal
              $modalData = [
                  'log_id' => $row['log_id'],
                  'created_at' => $row['created_at'],
                  'formatted_time' => formatDateTime($row['created_at']),
                  'actor' => $row['actor'],
                  'actor_role' => ucwords(str_replace('_', ' ', $row['actor_role'] ?? 'user')),
                  'user_id' => $row['user_id'],
                  'ip_address' => $row['ip_address'] ?: '127.0.0.1',
                  'device' => $ua['device'],
                  'browser' => $ua['browser'],
                  'user_agent' => $row['user_agent'] ?: 'N/A',
                  'category' => $row['event_category'],
                  'action' => $row['action'],
                  'severity' => $sev,
                  'target_type' => $row['target_type'],
                  'target_id' => $row['target_id'],
                  'description' => $row['description'],
                  'correlation_id' => $row['correlation_id'],
                  'old_values' => $row['old_values'],
                  'new_values' => $row['new_values'],
              ];
              $jsonData = htmlspecialchars(json_encode($modalData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
            ?>
              <tr>
                <!-- Time -->
                <td>
                  <div class="fw-semibold text-dark" style="font-size: 0.82rem;">
                    <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                  </div>
                  <div class="text-muted small">
                    <?php echo date('h:i A', strtotime($row['created_at'])); ?>
                  </div>
                </td>

                <!-- Actor -->
                <td>
                  <div class="fw-bold text-dark text-truncate" style="max-width: 150px;" title="<?php echo e($row['actor']); ?>">
                    <?php echo e($row['actor']); ?>
                  </div>
                  <span class="badge <?php echo $roleBadgeClass; ?> mt-1" style="font-size: 0.7rem;">
                    <?php echo e(ucwords(str_replace('_', ' ', $actorRole))); ?>
                  </span>
                </td>

                <!-- IP & Device -->
                <td>
                  <div class="font-monospace text-secondary small">
                    <?php echo e($row['ip_address'] ?: '127.0.0.1'); ?>
                  </div>
                  <div class="device-hint mt-1">
                    <i class="fas <?php echo $ua['icon']; ?> text-muted"></i>
                    <span><?php echo e($ua['browser']); ?> &bull; <?php echo e($ua['device']); ?></span>
                  </div>
                </td>

                <!-- Event & Severity -->
                <td>
                  <div class="d-flex align-items-center gap-1 mb-1">
                    <span class="severity-pill <?php echo $sevClass; ?>">
                      <i class="fas <?php echo $sevIcon; ?>"></i> <?php echo e($sev); ?>
                    </span>
                    <span class="category-tag"><?php echo e($row['event_category']); ?></span>
                  </div>
                  <div class="fw-semibold text-dark" style="font-size: 0.82rem;">
                    <?php echo e($cleanAction); ?>
                  </div>
                </td>

                <!-- Target -->
                <td>
                  <div class="text-dark fw-medium text-truncate" style="max-width: 150px;">
                    <i class="fas fa-cube text-muted me-1 small"></i><?php echo e(ucwords(str_replace('_', ' ', $row['target_type']))); ?>
                  </div>
                  <?php if($row['target_id']): ?>
                    <span class="badge bg-light text-secondary border font-monospace mt-1" style="font-size: 0.72rem;">
                      #<?php echo e($row['target_id']); ?>
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Summary -->
                <td>
                  <div class="text-secondary" style="font-size: 0.83rem; line-height: 1.35;">
                    <?php echo e($row['description']); ?>
                  </div>
                  <?php if($hasDiff): ?>
                    <div class="mt-1">
                      <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 0.68rem;">
                        <i class="fas fa-code-compare me-1"></i>Changelog Available
                      </span>
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Action -->
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-audit-detail" 
                          data-audit='<?php echo $jsonData; ?>' 
                          title="View Details and Changes">
                    <i class="fas fa-eye me-1"></i>Details
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Pagination -->
  <?php if($data['pages'] > 1): ?>
    <nav class="mt-3 mb-5" aria-label="Audit log pagination">
      <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <?php if($data['page'] > 1): ?>
          <li class="page-item">
            <a class="page-link" href="?<?php echo e(http_build_query(array_merge($base, ['page' => $data['page'] - 1]))); ?>">&laquo; Previous</a>
          </li>
        <?php endif; ?>

        <?php for($p = max(1, $data['page'] - 2); $p <= min($data['pages'], $data['page'] + 2); $p++): ?>
          <li class="page-item <?php echo $p === $data['page'] ? 'active' : ''; ?>">
            <a class="page-link" href="?<?php echo e(http_build_query(array_merge($base, ['page' => $p]))); ?>"><?php echo $p; ?></a>
          </li>
        <?php endfor; ?>

        <?php if($data['page'] < $data['pages']): ?>
          <li class="page-item">
            <a class="page-link" href="?<?php echo e(http_build_query(array_merge($base, ['page' => $data['page'] + 1]))); ?>">Next &raquo;</a>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<!-- Interactive "View Details" Modal with Diff Viewer -->
<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
      <div class="modal-header border-bottom py-3 px-4 bg-light">
        <div class="d-flex align-items-center gap-2">
          <div class="p-2 rounded-circle bg-white text-primary shadow-sm">
            <i class="fas fa-shield-halved"></i>
          </div>
          <div>
            <h5 class="modal-title h6 fw-bold mb-0 text-dark" id="auditDetailModalLabel">Audit Event Details</h5>
            <small class="text-muted" id="modalEventId">Event #0</small>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-4">
        <!-- Top Status Banner -->
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 rounded-3 mb-4 bg-light border">
          <div class="d-flex align-items-center gap-2">
            <span id="modalSeverityBadge" class="severity-pill severity-info">INFO</span>
            <span id="modalCategoryBadge" class="category-tag">SYSTEM</span>
            <strong id="modalAction" class="text-dark ms-1">ACTION_NAME</strong>
          </div>
          <div class="text-secondary small mt-2 mt-sm-0" id="modalTime">
            <i class="far fa-clock me-1"></i> Timestamp
          </div>
        </div>

        <!-- Section 1: Summary Sentence -->
        <div class="card mb-3 border bg-white">
          <div class="card-body p-3">
            <label class="form-label fw-bold small text-secondary mb-1">
              <i class="fas fa-align-left me-1 text-primary"></i>Action Summary
            </label>
            <div id="modalDescription" class="text-dark fw-medium" style="font-size: 0.95rem; line-height: 1.5;">
              Summary text here.
            </div>
          </div>
        </div>

        <!-- Section 2: Metadata Cards Grid -->
        <div class="row g-3 mb-4">
          <!-- Actor Info -->
          <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100 bg-white">
              <div class="fw-bold small text-secondary mb-2">
                <i class="fas fa-user-shield me-1 text-primary"></i>Actor Information
              </div>
              <table class="table table-sm table-borderless mb-0 small">
                <tr>
                  <td class="text-muted" style="width: 35%;">Name:</td>
                  <td><strong id="modalActorName" class="text-dark">Admin</strong></td>
                </tr>
                <tr>
                  <td class="text-muted">Role:</td>
                  <td><span id="modalActorRole" class="badge bg-light text-secondary border">Role</span></td>
                </tr>
                <tr>
                  <td class="text-muted">User ID:</td>
                  <td id="modalActorId" class="font-monospace text-dark">#1</td>
                </tr>
              </table>
            </div>
          </div>

          <!-- Network & Environment -->
          <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100 bg-white">
              <div class="fw-bold small text-secondary mb-2">
                <i class="fas fa-network-wired me-1 text-primary"></i>Network & Environment
              </div>
              <table class="table table-sm table-borderless mb-0 small">
                <tr>
                  <td class="text-muted" style="width: 35%;">Client IP:</td>
                  <td><code id="modalClientIp" class="text-dark fw-bold">127.0.0.1</code></td>
                </tr>
                <tr>
                  <td class="text-muted">Device / Agent:</td>
                  <td id="modalDeviceBrowser" class="text-dark">Chrome / Desktop</td>
                </tr>
                <tr>
                  <td class="text-muted">Tracking Ref:</td>
                  <td><span id="modalCorrelationId" class="font-monospace text-muted" style="font-size: 0.75rem;">—</span></td>
                </tr>
              </table>
            </div>
          </div>
        </div>

        <!-- Section 3: Target Entity -->
        <div class="card mb-4 border bg-white">
          <div class="card-body p-3">
            <div class="fw-bold small text-secondary mb-2">
              <i class="fas fa-crosshairs me-1 text-primary"></i>Target Entity
            </div>
            <div class="d-flex align-items-center gap-3">
              <div>
                <span class="text-muted small">Entity Type:</span>
                <strong id="modalTargetType" class="text-dark ms-1">requests</strong>
              </div>
              <div class="border-start ps-3">
                <span class="text-muted small">Record ID:</span>
                <span id="modalTargetId" class="badge bg-light text-secondary border font-monospace ms-1">#104</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Section 4: Before & After Diff Changelog -->
        <div class="card border mb-3">
          <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center">
            <div class="fw-bold small text-dark">
              <i class="fas fa-code-compare me-1 text-primary"></i>Record Mutations (Before & After Diff)
            </div>
            <span id="modalDiffCount" class="badge bg-secondary rounded-pill" style="font-size: 0.72rem;">0 Changes</span>
          </div>
          <div class="card-body p-0">
            <div id="modalDiffContainer" class="table-responsive" style="max-height: 280px; overflow-y: auto;">
              <!-- Populated via JavaScript -->
            </div>
            <div id="modalNoDiff" class="text-center py-4 text-muted small" style="display: none;">
              <i class="fas fa-check-circle text-success me-1"></i> No record attribute mutations recorded for this event.
            </div>
          </div>
        </div>

        <!-- Collapsible Raw JSON Snapshot -->
        <div class="border rounded-3 p-2 bg-light">
          <button class="btn btn-sm btn-link text-decoration-none text-secondary p-0 w-100 text-start d-flex justify-content-between align-items-center" 
                  type="button" data-bs-toggle="collapse" data-bs-target="#modalRawJsonCollapse" aria-expanded="false" aria-controls="modalRawJsonCollapse">
            <span class="small fw-semibold"><i class="fas fa-terminal me-1"></i> Technical Audit JSON Payload</span>
            <i class="fas fa-chevron-down small"></i>
          </button>
          <div class="collapse mt-2" id="modalRawJsonCollapse">
            <pre id="modalRawJson" class="p-3 bg-dark text-light rounded small mb-0 font-monospace" style="max-height: 200px; overflow: auto;"></pre>
          </div>
        </div>
      </div>

      <div class="modal-footer border-top py-2 px-4 bg-light">
        <button type="button" class="btn btn-sm btn-secondary fw-semibold px-3" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var modalEl = document.getElementById('auditDetailModal');
  if (!modalEl) return;
  var bsModal = new bootstrap.Modal(modalEl);

  function safeJsonParse(val) {
    if (!val) return null;
    if (typeof val === 'object') return val;
    try {
      return JSON.parse(val);
    } catch (e) {
      return null;
    }
  }

  function formatAttrValue(val) {
    if (val === null || val === undefined) return '<span class="text-muted font-italic">null</span>';
    if (typeof val === 'boolean') return val ? 'true' : 'false';
    if (typeof val === 'object') return JSON.stringify(val);
    return String(val);
  }

  function renderDiffTable(oldVal, newVal) {
    var oldObj = safeJsonParse(oldVal) || {};
    var newObj = safeJsonParse(newVal) || {};

    var allKeys = {};
    if (typeof oldObj === 'object' && oldObj !== null) {
      Object.keys(oldObj).forEach(function(k) { allKeys[k] = true; });
    }
    if (typeof newObj === 'object' && newObj !== null) {
      Object.keys(newObj).forEach(function(k) { allKeys[k] = true; });
    }

    var keys = Object.keys(allKeys);
    if (keys.length === 0) {
      // Check if there are raw string values
      if (oldVal || newVal) {
        return '<table class="table table-bordered diff-table mb-0">' +
          '<thead><tr class="bg-light"><th>Attribute</th><th>Previous Value</th><th>New Value</th></tr></thead>' +
          '<tbody><tr>' +
          '<td><strong>payload</strong></td>' +
          '<td class="diff-old">' + (oldVal ? String(oldVal) : '—') + '</td>' +
          '<td class="diff-new">' + (newVal ? String(newVal) : '—') + '</td>' +
          '</tr></tbody></table>';
      }
      return '';
    }

    var rowsHtml = '';
    var changedCount = 0;

    keys.forEach(function(k) {
      var oVal = oldObj ? oldObj[k] : undefined;
      var nVal = newObj ? newObj[k] : undefined;
      var isChanged = JSON.stringify(oVal) !== JSON.stringify(nVal);

      if (isChanged) changedCount++;

      var prettyKey = k.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
      rowsHtml += '<tr>' +
        '<td class="fw-semibold text-dark" style="width: 25%;">' + prettyKey + '</td>' +
        '<td class="' + (isChanged && oVal !== undefined ? 'diff-old' : 'text-muted') + '" style="width: 37.5%;">' +
          (oVal !== undefined ? formatAttrValue(oVal) : '<span class="text-muted">—</span>') +
        '</td>' +
        '<td class="' + (isChanged && nVal !== undefined ? 'diff-new' : 'text-dark') + '" style="width: 37.5%;">' +
          (nVal !== undefined ? formatAttrValue(nVal) : '<span class="text-muted">—</span>') +
        '</td>' +
      '</tr>';
    });

    return {
      html: '<table class="table table-bordered diff-table mb-0 align-middle">' +
        '<thead><tr class="bg-light"><th style="width: 25%;">Field Name</th><th style="width: 37.5%;">Previous Value</th><th style="width: 37.5%;">Updated Value</th></tr></thead>' +
        '<tbody>' + rowsHtml + '</tbody></table>',
      count: changedCount > 0 ? changedCount : keys.length
    };
  }

  document.querySelectorAll('.btn-audit-detail').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var raw = this.getAttribute('data-audit');
      if (!raw) return;
      var d = JSON.parse(raw);

      // Populate basic info
      document.getElementById('modalEventId').textContent = 'Event #' + d.log_id;
      document.getElementById('modalAction').textContent = (d.action || '').replace(/_/g, ' ');
      document.getElementById('modalTime').innerHTML = '<i class="far fa-clock me-1"></i>' + d.formatted_time;
      document.getElementById('modalDescription').textContent = d.description || 'No description available.';

      // Severity & Category
      var sevEl = document.getElementById('modalSeverityBadge');
      var sev = d.severity || 'INFO';
      sevEl.textContent = sev;
      sevEl.className = 'severity-pill ' + (
        sev === 'CRITICAL' ? 'severity-critical' :
        sev === 'WARNING' ? 'severity-warning' : 'severity-info'
      );
      document.getElementById('modalCategoryBadge').textContent = d.category || 'SYSTEM';

      // Actor Information
      document.getElementById('modalActorName').textContent = d.actor || 'System';
      document.getElementById('modalActorRole').textContent = d.actor_role || 'user';
      document.getElementById('modalActorId').textContent = d.user_id ? '#' + d.user_id : 'N/A';

      // Network & Environment
      document.getElementById('modalClientIp').textContent = d.ip_address || '127.0.0.1';
      document.getElementById('modalDeviceBrowser').textContent = (d.browser || 'Browser') + ' (' + (d.device || 'Desktop') + ')';
      document.getElementById('modalCorrelationId').textContent = d.correlation_id || '—';

      // Target Entity
      document.getElementById('modalTargetType').textContent = (d.target_type || 'system').replace(/_/g, ' ');
      document.getElementById('modalTargetId').textContent = d.target_id ? '#' + d.target_id : 'N/A';

      // Before & After Diff
      var diffRes = renderDiffTable(d.old_values, d.new_values);
      var diffContainer = document.getElementById('modalDiffContainer');
      var noDiffEl = document.getElementById('modalNoDiff');
      var diffCountBadge = document.getElementById('modalDiffCount');

      if (diffRes && diffRes.html) {
        diffContainer.innerHTML = diffRes.html;
        diffContainer.style.display = 'block';
        noDiffEl.style.display = 'none';
        diffCountBadge.textContent = diffRes.count + ' Item' + (diffRes.count === 1 ? '' : 's');
        diffCountBadge.className = 'badge bg-primary rounded-pill';
      } else {
        diffContainer.innerHTML = '';
        diffContainer.style.display = 'none';
        noDiffEl.style.display = 'block';
        diffCountBadge.textContent = '0 Changes';
        diffCountBadge.className = 'badge bg-secondary rounded-pill';
      }

      // Raw JSON Payload
      document.getElementById('modalRawJson').textContent = JSON.stringify(d, null, 2);

      bsModal.show();
    });
  });
});
</script>

<?php include '../templates/footer.php'; ?>
