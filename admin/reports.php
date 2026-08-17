<?php
/**
 * Reports & Analytics Page
 * Live analytics for parish activity, requests, records, reservations, and announcements.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('reports.view');
ensureScheduleEventsTable($conn);

$page_title = 'Analytics Report Dashboard';
$hide_global_header = true;
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$selected_report = $_GET['report'] ?? '';
$search = trim($_GET['q'] ?? '');
$is_generated_report = isset($_GET['generate_report']);
$report_row_limit = 25;
$trend_row_limit = 12;

$report_categories = [
    'activity' => [
        'title' => 'Activity Logs Report',
        'description' => 'Review user actions, roles, timestamps, approvals, updates, registrations, and announcement activity.',
        'icon' => 'fa-shield-halved'
    ],
    'requests' => [
        'title' => 'Certificate Request Reports',
        'description' => 'Inspect certificate request IDs, parishioners, request types, dates, and status counts.',
        'icon' => 'fa-certificate'
    ],
    'records' => [
        'title' => 'Sacramental Records Reports',
        'description' => 'Monitor Baptism, First Communion, Confirmation, and Marriage record totals.',
        'icon' => 'fa-book-bible'
    ],
    'registrations' => [
        'title' => 'Parishioner Registration Reports',
        'description' => 'Track registered parishioners, verified accounts, pending verification, and registration dates.',
        'icon' => 'fa-user-check'
    ],
    'chatbot' => [
        'title' => 'AI Chatbot Inquiry Reports',
        'description' => 'Review chatbot interactions, common questions, and inquiry activity trends.',
        'icon' => 'fa-robot'
    ],
    'announcements' => [
        'title' => 'Announcements Reports',
        'description' => 'View announcement titles, posting dates, audiences, and posting staff.',
        'icon' => 'fa-bullhorn'
    ]
];

if ($is_generated_report && $selected_report === '') {
    $selected_report = 'all';
}
if ($selected_report !== 'all' && !array_key_exists($selected_report, $report_categories)) {
    $selected_report = '';
}

$current_report_title = 'Analytics Report Dashboard';
$current_report_description = 'Organized parish transaction, sacramental record, registration, announcement, activity log, and TUGON AI inquiry analytics.';
$current_report_icon = 'fa-chart-simple';
if ($selected_report === 'all') {
    $current_report_title = 'Complete Analytics Report';
    $current_report_description = 'Complete formal parish analytics report covering activity logs, certificate requests, sacramental records, registrations, chatbot inquiries, announcements, and key metrics.';
} elseif ($selected_report) {
    $current_report_title = $report_categories[$selected_report]['title'];
    $current_report_description = $report_categories[$selected_report]['description'];
    $current_report_icon = $report_categories[$selected_report]['icon'];
}

function reportIncludes($selected_report, $section) {
    return $selected_report === 'all' || $selected_report === $section;
}

function reportSectionNumber($selected_report, $section, $fallback = 'I.') {
    if ($selected_report !== 'all') {
        return $fallback;
    }

    $sections = [
        'activity' => 'I.',
        'requests' => 'II.',
        'records' => 'III.',
        'registrations' => 'IV.',
        'chatbot' => 'V.',
        'announcements' => 'VI.',
        'overview' => 'VII.'
    ];

    return $sections[$section] ?? $fallback;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}

$from_sql = $conn->real_escape_string($from . ' 00:00:00');
$to_sql = $conn->real_escape_string($to . ' 23:59:59');

// Fetch Count Function - Documents this helper's role in the parish management workflow.
function fetchCount($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int) ($row['count'] ?? 0);
}

// Fetch Rows Function - Documents this helper's role in the parish management workflow.
function fetchRows($conn, $sql) {
    $rows = [];
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

// Ensure Report Archive Column Function - Documents this helper's role in the parish management workflow.
function ensureReportArchiveColumn($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'deleted_at'");
    if ($result && $result->num_rows > 0) {
        return;
    }

    $conn->query("ALTER TABLE `$table` ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
}

// Percent Of Function - Documents this helper's role in the parish management workflow.
function percentOf($value, $total) {
    if ($total <= 0) {
        return 0;
    }

    return round(($value / $total) * 100, 1);
}

// Top Report Row Function - Documents this helper's role in the parish management workflow.
function topReportRow($rows) {
    if (empty($rows)) {
        return null;
    }

    usort($rows, function($a, $b) {
        return intval($b['count'] ?? 0) <=> intval($a['count'] ?? 0);
    });

    return $rows[0];
}

// Row Matches Search Function - Documents this helper's role in the parish management workflow.
function rowMatchesSearch($row, $search) {
    if ($search === '') {
        return true;
    }

    $needle = mb_strtolower($search);
    foreach ($row as $value) {
        if ($value !== null && mb_strpos(mb_strtolower((string) $value), $needle) !== false) {
            return true;
        }
    }

    return false;
}

// Build AI Report Insights Function - Documents this helper's role in the parish management workflow.
function buildAiReportInsights($summary, $request_status, $request_types, $reservation_status, $announcement_types, $from, $to) {
    $insights = [];
    $recommendations = [];
    $alerts = [];

    $total_requests = intval($summary['requests']);
    $pending_requests = intval($summary['pending_requests']);
    $completed_requests = intval($summary['completed_requests']);
    $completion_rate = $total_requests > 0 ? round(($completed_requests / $total_requests) * 100, 1) : 0;
    $pending_rate = $total_requests > 0 ? round(($pending_requests / $total_requests) * 100, 1) : 0;
    $top_request_type = topReportRow($request_types);
    $top_reservation_status = topReportRow($reservation_status);
    $top_announcement_type = topReportRow($announcement_types);

    $insights[] = [
        'label' => 'Report window',
        'value' => formatDate($from) . ' to ' . formatDate($to),
        'icon' => 'fa-calendar-days'
    ];

    $insights[] = [
        'label' => 'Request completion',
        'value' => $completion_rate . '% completed',
        'icon' => 'fa-circle-check'
    ];

    $insights[] = [
        'label' => 'Pending workload',
        'value' => $pending_requests . ' pending (' . $pending_rate . '%)',
        'icon' => 'fa-hourglass-half'
    ];

    if ($top_request_type) {
        $insights[] = [
            'label' => 'Most requested',
            'value' => ucfirst(str_replace('_', ' ', $top_request_type['label'])) . ' (' . intval($top_request_type['count']) . ')',
            'icon' => 'fa-ranking-star'
        ];
    }

    if ($pending_requests >= 10 || $pending_rate >= 40) {
        $alerts[] = 'High pending queue detected. Prioritize the oldest pending requests and assign staff for faster review.';
    } elseif ($pending_requests > 0) {
        $recommendations[] = 'Pending requests are manageable. Review incomplete requirements first to avoid delays.';
    } else {
        $recommendations[] = 'No pending requests in this range. Keep monitoring new submissions daily.';
    }

    if ($completion_rate < 35 && $total_requests > 0) {
        $alerts[] = 'Completion rate is low for the selected range. Check whether requests are stuck in processing.';
    } elseif ($completion_rate >= 70) {
        $recommendations[] = 'Completion rate is strong. Continue the current processing workflow.';
    }

    if (intval($summary['schedules']) === 0) {
        $recommendations[] = 'No schedules were created in this range. Add public calendar entries for upcoming parish activities.';
    }

    if (intval($summary['announcements']) === 0) {
        $recommendations[] = 'No announcements were posted. Use announcements to keep parishioners informed about schedules and requirements.';
    } elseif ($top_announcement_type) {
        $recommendations[] = 'Announcement activity is led by ' . ucfirst(str_replace('_', ' ', $top_announcement_type['label'])) . '. Keep urgent parish notices visible.';
    }

    if ($top_reservation_status && $top_reservation_status['label'] === 'pending') {
        $recommendations[] = 'Reservations are mostly pending. Confirm schedule availability before approving to avoid calendar conflicts.';
    }

    return [
        'insights' => $insights,
        'alerts' => $alerts,
        'recommendations' => $recommendations
    ];
}

ensureReportArchiveColumn($conn, 'requests');
ensureReportArchiveColumn($conn, 'announcements');
ensureChatbotInquirySchema($conn);

$request_status = fetchRows($conn, "SELECT status AS label, COUNT(*) AS count FROM requests WHERE deleted_at IS NULL AND date_requested BETWEEN '$from_sql' AND '$to_sql' GROUP BY status ORDER BY count DESC");
$request_types = fetchRows($conn, "SELECT request_type AS label, COUNT(*) AS count FROM requests WHERE deleted_at IS NULL AND date_requested BETWEEN '$from_sql' AND '$to_sql' GROUP BY request_type ORDER BY count DESC");
$certificate_requests = fetchRows($conn, "SELECT r.request_id, r.reference_number, r.request_type, r.status, r.date_requested, u.fullname
    FROM requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.deleted_at IS NULL
      AND r.date_requested BETWEEN '$from_sql' AND '$to_sql'
      AND r.request_type IN ('baptismal', 'confirmation', 'first_communion', 'baptismal_certificate', 'confirmation_certificate', 'first_communion_certificate')
    ORDER BY FIELD(r.status, 'pending', 'approved', 'completed', 'rejected', 'processing'), r.date_requested DESC
    LIMIT $report_row_limit");
$reservation_status = fetchRows($conn, "SELECT status AS label, COUNT(*) AS count FROM reservations WHERE created_at BETWEEN '$from_sql' AND '$to_sql' GROUP BY status ORDER BY count DESC");
$reservation_types = fetchRows($conn, "SELECT reservation_type AS label, COUNT(*) AS count FROM reservations WHERE created_at BETWEEN '$from_sql' AND '$to_sql' GROUP BY reservation_type ORDER BY count DESC");
$announcement_types = fetchRows($conn, "SELECT type AS label, COUNT(*) AS count FROM announcements WHERE deleted_at IS NULL AND published_date BETWEEN '$from_sql' AND '$to_sql' GROUP BY type ORDER BY count DESC");
$announcement_rows = fetchRows($conn, "SELECT a.announcement_id, a.title, a.type, a.status, a.published_date, a.expiry_date, COALESCE(u.fullname, 'Parish Office') AS posted_by
    FROM announcements a
    LEFT JOIN users u ON a.published_by = u.id
    WHERE a.deleted_at IS NULL AND a.published_date BETWEEN '$from_sql' AND '$to_sql'
    ORDER BY a.published_date DESC
    LIMIT $report_row_limit");

$from_date_sql = $conn->real_escape_string($from);
$to_date_sql = $conn->real_escape_string($to);

$record_totals = [
    ['label' => 'Baptism', 'count' => fetchCount($conn, "SELECT COUNT(*) AS count FROM baptism_records WHERE status = 'active' AND baptism_date BETWEEN '$from_date_sql' AND '$to_date_sql'")],
    ['label' => 'First Communion', 'count' => fetchCount($conn, "SELECT COUNT(*) AS count FROM first_communion_records WHERE status = 'active' AND communion_date BETWEEN '$from_date_sql' AND '$to_date_sql'")],
    ['label' => 'Confirmation', 'count' => fetchCount($conn, "SELECT COUNT(*) AS count FROM confirmation_records WHERE status = 'active' AND confirmation_date BETWEEN '$from_date_sql' AND '$to_date_sql'")],
    ['label' => 'Marriage', 'count' => fetchCount($conn, "SELECT COUNT(*) AS count FROM marriage_records WHERE status = 'active' AND wedding_date BETWEEN '$from_date_sql' AND '$to_date_sql'")]
];

$sacramental_record_rows = fetchRows($conn, "
    SELECT 'Baptism' AS record_type,
           b.baptism_id AS record_id,
           b.registry_no,
           b.fullname AS person_name,
           COALESCE(u.fullname, 'Parish Office / Manual Entry') AS requested_by,
           COALESCE(r.reference_number, '') AS reference_number,
           b.baptism_date AS record_date,
           b.status
    FROM baptism_records b
    LEFT JOIN requests r ON b.request_id = r.request_id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE b.status = 'active' AND b.baptism_date BETWEEN '$from_date_sql' AND '$to_date_sql'

    UNION ALL

    SELECT 'First Communion' AS record_type,
           c.communion_id AS record_id,
           c.registry_no,
           c.fullname AS person_name,
           COALESCE(u.fullname, 'Parish Office / Manual Entry') AS requested_by,
           COALESCE(r.reference_number, '') AS reference_number,
           c.communion_date AS record_date,
           c.status
    FROM first_communion_records c
    LEFT JOIN requests r ON c.request_id = r.request_id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE c.status = 'active' AND c.communion_date BETWEEN '$from_date_sql' AND '$to_date_sql'

    UNION ALL

    SELECT 'Confirmation' AS record_type,
           cn.confirmation_id AS record_id,
           cn.registry_no,
           cn.fullname AS person_name,
           COALESCE(u.fullname, 'Parish Office / Manual Entry') AS requested_by,
           COALESCE(r.reference_number, '') AS reference_number,
           cn.confirmation_date AS record_date,
           cn.status
    FROM confirmation_records cn
    LEFT JOIN requests r ON cn.request_id = r.request_id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE cn.status = 'active' AND cn.confirmation_date BETWEEN '$from_date_sql' AND '$to_date_sql'

    UNION ALL

    SELECT 'Marriage' AS record_type,
           m.marriage_id AS record_id,
           m.registry_no,
           CONCAT(m.husband_name, ' and ', m.wife_name) AS person_name,
           COALESCE(u.fullname, 'Parish Office / Manual Entry') AS requested_by,
           COALESCE(r.reference_number, '') AS reference_number,
           m.wedding_date AS record_date,
           m.status
    FROM marriage_records m
    LEFT JOIN requests r ON m.request_id = r.request_id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE m.status = 'active' AND m.wedding_date BETWEEN '$from_date_sql' AND '$to_date_sql'
    ORDER BY record_date DESC, record_type ASC, person_name ASC
    LIMIT $report_row_limit
");

if ($search !== '') {
    $sacramental_record_rows = array_values(array_filter($sacramental_record_rows, function ($row) use ($search) {
        return rowMatchesSearch($row, $search);
    }));
}

$summary = [
    'new_users' => fetchCount($conn, "SELECT COUNT(*) AS count FROM users WHERE role = 'user' AND created_at BETWEEN '$from_sql' AND '$to_sql'"),
    'requests' => fetchCount($conn, "SELECT COUNT(*) AS count FROM requests WHERE deleted_at IS NULL AND date_requested BETWEEN '$from_sql' AND '$to_sql'"),
    'pending_requests' => fetchCount($conn, "SELECT COUNT(*) AS count FROM requests WHERE deleted_at IS NULL AND status = 'pending' AND date_requested BETWEEN '$from_sql' AND '$to_sql'"),
    'completed_requests' => fetchCount($conn, "SELECT COUNT(*) AS count FROM requests WHERE deleted_at IS NULL AND status = 'completed' AND date_requested BETWEEN '$from_sql' AND '$to_sql'"),
    'reservations' => fetchCount($conn, "SELECT COUNT(*) AS count FROM reservations WHERE created_at BETWEEN '$from_sql' AND '$to_sql'"),
    'announcements' => fetchCount($conn, "SELECT COUNT(*) AS count FROM announcements WHERE deleted_at IS NULL AND published_date BETWEEN '$from_sql' AND '$to_sql'"),
    'schedules' => fetchCount($conn, "SELECT COUNT(*) AS count FROM schedule_events WHERE created_at BETWEEN '$from_sql' AND '$to_sql'")
];
$summary['records'] = array_sum(array_column($record_totals, 'count'));
$summary['verified_users'] = fetchCount($conn, "SELECT COUNT(*) AS count FROM users WHERE role = 'user' AND (status = 'active' OR verified_at IS NOT NULL)");
$summary['total_users'] = fetchCount($conn, "SELECT COUNT(*) AS count FROM users WHERE role = 'user'");
$summary['pending_verification'] = fetchCount($conn, "SELECT COUNT(*) AS count FROM users WHERE role = 'user' AND status IN ('pending', 'pending_verification')");
$summary['chatbot_interactions'] = fetchCount($conn, "SELECT COUNT(*) AS count FROM chatbot_inquiries WHERE created_at BETWEEN '$from_sql' AND '$to_sql'");
$summary['audit_logs'] = (tableExists($conn, 'audit_log') ? fetchCount($conn, "SELECT COUNT(*) AS count FROM audit_log WHERE created_at BETWEEN '$from_sql' AND '$to_sql'") : 0)
    + (tableExists($conn, 'audit_logs') ? fetchCount($conn, "SELECT COUNT(*) AS count FROM audit_logs WHERE `timestamp` BETWEEN '$from_sql' AND '$to_sql'") : 0);

$recent_registrations = fetchRows($conn, "SELECT id, fullname, email, status, verified_at, created_at
    FROM users
    WHERE role = 'user' AND created_at BETWEEN '$from_sql' AND '$to_sql'
    ORDER BY created_at DESC
    LIMIT $report_row_limit");

$chatbot_top_questions = fetchRows($conn, "SELECT question AS label, COUNT(*) AS count
    FROM chatbot_inquiries
    WHERE created_at BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY question
    ORDER BY count DESC, MAX(created_at) DESC
    LIMIT 8");

$chatbot_trends = fetchRows($conn, "SELECT DATE(created_at) AS label, COUNT(*) AS count
    FROM chatbot_inquiries
    WHERE created_at BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY DATE(created_at)
    ORDER BY label ASC
    LIMIT $trend_row_limit");

$daily_request_trends = fetchRows($conn, "SELECT DATE(date_requested) AS label, COUNT(*) AS count
    FROM requests
    WHERE deleted_at IS NULL AND date_requested BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY DATE(date_requested)
    ORDER BY label ASC
    LIMIT $trend_row_limit");

$monthly_trends = fetchRows($conn, "SELECT DATE_FORMAT(date_requested, '%Y-%m') AS label, COUNT(*) AS count
    FROM requests
    WHERE deleted_at IS NULL AND date_requested BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY DATE_FORMAT(date_requested, '%Y-%m')
    ORDER BY label ASC");

$yearly_trends = fetchRows($conn, "SELECT YEAR(date_requested) AS label, COUNT(*) AS count
    FROM requests
    WHERE deleted_at IS NULL AND date_requested BETWEEN '$from_sql' AND '$to_sql'
    GROUP BY YEAR(date_requested)
    ORDER BY label ASC");

$recent_activity = fetchRows($conn, "SELECT 'Request' AS module, request_id AS item_id, reference_number AS title, status, date_requested AS activity_date FROM requests WHERE deleted_at IS NULL AND date_requested BETWEEN '$from_sql' AND '$to_sql'
    UNION ALL
    SELECT 'Registration' AS module, id AS item_id, fullname AS title, status, created_at AS activity_date FROM users WHERE role = 'user' AND created_at BETWEEN '$from_sql' AND '$to_sql'
    UNION ALL
    SELECT 'Reservation' AS module, reservation_id AS item_id, reservation_type AS title, status, created_at AS activity_date FROM reservations WHERE created_at BETWEEN '$from_sql' AND '$to_sql'
    UNION ALL
    SELECT 'Announcement' AS module, announcement_id AS item_id, title, status, published_date AS activity_date FROM announcements WHERE deleted_at IS NULL AND published_date BETWEEN '$from_sql' AND '$to_sql'
    ORDER BY activity_date DESC LIMIT 12");

$audit_source_queries = [];
if (tableExists($conn, 'audit_logs')) {
    $audit_source_queries[] = "SELECT log_id, user_id, action_type AS action_name, table_name, record_id, `timestamp` AS activity_date, 'audit_logs' AS source_table FROM audit_logs";
}
if (tableExists($conn, 'audit_log')) {
    $audit_source_queries[] = "SELECT log_id, user_id, action AS action_name, table_name, record_id, created_at AS activity_date, 'audit_log' AS source_table FROM audit_log";
}
$activity_logs = [];
if (!empty($audit_source_queries)) {
    $audit_union = implode(' UNION ALL ', $audit_source_queries);
    $activity_logs = fetchRows($conn, "SELECT l.*, COALESCE(u.fullname, 'System') AS admin_name, COALESCE(u.role, 'system') AS admin_role
        FROM ($audit_union) l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE l.activity_date BETWEEN '$from_sql' AND '$to_sql'
        ORDER BY l.activity_date DESC
        LIMIT $report_row_limit");
}

if ($search !== '') {
    if (reportIncludes($selected_report, 'requests')) {
        $certificate_requests = array_values(array_filter($certificate_requests, function($row) use ($search) {
            return rowMatchesSearch($row, $search);
        }));
    }
    if (reportIncludes($selected_report, 'registrations')) {
        $recent_registrations = array_values(array_filter($recent_registrations, function($row) use ($search) {
            return rowMatchesSearch($row, $search);
        }));
    }
    if (reportIncludes($selected_report, 'chatbot')) {
        $chatbot_top_questions = array_values(array_filter($chatbot_top_questions, function($row) use ($search) {
            return rowMatchesSearch($row, $search);
        }));
    }
    if (reportIncludes($selected_report, 'announcements')) {
        $announcement_rows = array_values(array_filter($announcement_rows, function($row) use ($search) {
            return rowMatchesSearch($row, $search);
        }));
        $announcement_types = array_values(array_filter($announcement_types, function($row) use ($search) {
            return rowMatchesSearch($row, $search);
        }));
    }
    if (reportIncludes($selected_report, 'activity')) {
        $recent_activity = array_values(array_filter($recent_activity, function($row) use ($search) {
            return rowMatchesSearch($row, $search);
        }));
        $activity_logs = array_values(array_filter($activity_logs, function($row) use ($search) {
            return rowMatchesSearch($row, $search);
        }));
    }
}

$ai_report = buildAiReportInsights($summary, $request_status, $request_types, $reservation_status, $announcement_types, $from, $to);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="analytics-report-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Analytics Report', $from, $to]);
    fputcsv($out, []);
    fputcsv($out, ['Summary', 'Count']);
    foreach ($summary as $label => $count) {
        fputcsv($out, [ucwords(str_replace('_', ' ', $label)), $count]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Request Status', 'Count']);
    foreach ($request_status as $row) {
        fputcsv($out, [ucfirst($row['label']), $row['count']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Request Type', 'Count']);
    foreach ($request_types as $row) {
        fputcsv($out, [ucfirst(str_replace('_', ' ', $row['label'])), $row['count']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Reservation Status', 'Count']);
    foreach ($reservation_status as $row) {
        fputcsv($out, [ucfirst($row['label']), $row['count']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Sacramental Records', 'Count']);
    foreach ($record_totals as $row) {
        fputcsv($out, [$row['label'], $row['count']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Sacramental Record Details', 'Name On Record', 'Requested By', 'Reference No.', 'Record Date', 'Status']);
    foreach ($sacramental_record_rows as $row) {
        fputcsv($out, [
            $row['record_type'],
            $row['person_name'],
            $row['requested_by'],
            $row['reference_number'] ?: 'Manual entry',
            $row['record_date'],
            $row['status']
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Parishioner Registration', 'Count']);
    fputcsv($out, ['Total Registered Parishioners', $summary['total_users']]);
    fputcsv($out, ['Verified Accounts', $summary['verified_users']]);
    fputcsv($out, ['Recent Registrations In Range', $summary['new_users']]);
    fputcsv($out, []);
    fputcsv($out, ['AI Chatbot Inquiry Reports', 'Count']);
    fputcsv($out, ['Total Interactions', $summary['chatbot_interactions']]);
    foreach ($chatbot_top_questions as $row) {
        fputcsv($out, [$row['label'], $row['count']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Announcements', 'Type', 'Status', 'Published Date', 'Target Audience']);
    foreach ($announcement_rows as $row) {
        fputcsv($out, [$row['title'], $row['type'], $row['status'], $row['published_date'], 'All Parishioners']);
    }
    fputcsv($out, []);
    fputcsv($out, ['Activity Logs', 'Admin/System', 'Action', 'Table', 'Record ID', 'Date']);
    foreach ($activity_logs as $row) {
        fputcsv($out, [$row['admin_name'], $row['action_name'], $row['table_name'], $row['record_id'], $row['activity_date']]);
    }
    fclose($out);
    exit;
}

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Reports' => null
];
?>
<?php include '../templates/header.php'; ?>

<style>
    .reports-page {
        max-width: 1500px;
        margin: 0 auto;
    }

    .reports-hero {
        background: #ffffff;
        border: 1px solid #dfe4ea;
        border-top: 4px solid #b68b2c;
        border-radius: 4px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.07);
        padding: 20px;
        margin-bottom: 16px;
    }

    .reports-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 6px;
        color: #111827;
        font-size: 1.45rem;
        font-weight: 800;
    }

    .reports-subtitle {
        max-width: 740px;
        color: #64748b;
        margin: 0;
    }

    .filter-panel {
        background: #f8fafc;
        border: 1px solid #dfe4ea;
        border-radius: 8px;
        padding: 14px;
    }

    .formal-report-title {
        display: none;
    }

    .filter-panel label {
        font-size: 0.75rem;
        font-weight: 800;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 4px;
    }

    .report-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .report-section {
        margin-top: 22px;
        padding-top: 2px;
    }

    .section-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #e5e7eb;
    }

    .section-heading h2 {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.02rem;
        font-weight: 800;
        color: #111827;
        margin: 0;
    }

    .section-heading h2::before {
        content: attr(data-section);
        display: inline-block;
        min-width: 1.8rem;
        color: #111827;
        font-family: Georgia, "Times New Roman", serif;
        font-weight: 900;
    }

    .section-heading span {
        color: #64748b;
        font-size: 0.88rem;
        text-align: right;
    }

    .analytics-card {
        display: block;
        border: 1px solid #dfe4ea;
        border-radius: 4px;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        min-height: 138px;
        color: inherit;
        text-decoration: none;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .analytics-card:hover,
    .analytics-card:focus {
        color: inherit;
        text-decoration: none;
        transform: translateY(-2px);
        border-color: rgba(182, 139, 44, 0.65);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
        outline: 2px solid rgba(182, 139, 44, 0.22);
        outline-offset: 2px;
    }

    .summary-card-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(150px, 1fr));
        gap: 12px;
        margin-bottom: 22px;
    }

    .report-category-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .report-category-card {
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 14px;
        min-height: 126px;
        padding: 18px;
        border: 1px solid #dfe4ea;
        border-radius: 4px;
        color: inherit;
        background: #ffffff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
        text-decoration: none;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .report-category-card:hover,
    .report-category-card:focus {
        color: inherit;
        text-decoration: none;
        transform: translateY(-2px);
        border-color: rgba(182, 139, 44, 0.65);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
    }

    .report-category-card h3 {
        margin: 0 0 5px;
        color: #111827;
        font-size: 1rem;
        font-weight: 800;
    }

    .report-category-card p {
        margin: 0;
        color: #64748b;
        font-size: 0.88rem;
        line-height: 1.45;
    }

    .report-category-card .metric-icon {
        margin: 0;
    }

    .report-category-card .open-icon {
        color: #94a3b8;
    }

    .summary-card-grid .analytics-card {
        background: #ffffff;
    }

    .summary-card-grid .card-body {
        display: grid;
        grid-template-rows: auto 1fr;
        gap: 14px;
        min-height: 134px;
        padding: 16px;
    }

    .analytics-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(320px, 0.75fr);
        gap: 16px;
        align-items: start;
    }

    .analytics-layout.equal {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .analytics-layout.single {
        grid-template-columns: 1fr;
    }

    .metric-icon {
        width: 42px;
        height: 42px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #eef5fb;
        color: #17446a;
    }

    .metric-value {
        font-size: 1.85rem;
        font-weight: 800;
        color: #111827;
        line-height: 1;
        margin-top: 7px;
    }

    .metric-label {
        color: #64748b;
        font-size: 0.76rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        line-height: 1.35;
    }

    .report-card {
        border: 1px solid #dfe4ea;
        border-radius: 4px;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
    }

    .report-card .card-body {
        padding: 18px;
    }

    .report-card .card-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #111827;
        font-size: 0.98rem;
        font-weight: 800;
        margin-bottom: 14px;
    }

    .formal-metrics-table {
        margin-top: 18px;
    }

    .section-note {
        color: #64748b;
        font-size: 0.88rem;
        margin: 0;
    }

    .overview-table {
        width: 100%;
    }

    .progress-row {
        display: block;
        padding: 10px 0;
        border-bottom: 1px solid #eef2f7;
        color: inherit;
        text-decoration: none;
    }

    .progress-row:last-child {
        border-bottom: 0;
    }

    a.progress-row:hover,
    a.progress-row:focus {
        color: inherit;
        text-decoration: none;
        background: #f8fafc;
        border-radius: 8px;
        padding-left: 10px;
        padding-right: 10px;
        outline: 0;
    }

    .clickable-row {
        cursor: pointer;
    }

    .clickable-row:hover td {
        background: #f8fafc;
    }

    .progress-label {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 6px;
        color: #334155;
    }

    .clean-table {
        margin-bottom: 0;
        display: table;
        width: 100%;
        table-layout: auto;
        border-collapse: collapse;
    }

    .report-section .clean-table thead {
        display: table-header-group;
    }

    .report-section .clean-table tbody {
        display: table-row-group;
    }

    .report-section .clean-table tr {
        display: table-row;
    }

    .report-section .clean-table th,
    .report-section .clean-table td {
        display: table-cell;
        width: auto;
    }

    .clean-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid #dfe4ea;
        white-space: nowrap;
    }

    .clean-table td {
        vertical-align: middle;
        color: #334155;
    }

    .empty-state {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        color: #64748b;
        padding: 18px;
        text-align: center;
    }

    .report-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 14px 0 20px;
        padding: 10px;
        border: 1px solid #dfe4ea;
        border-radius: 8px;
        background: #ffffff;
    }

    .report-nav a {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 38px;
        padding: 8px 11px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        color: #334155;
        background: #f8fafc;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 800;
    }

    .report-nav a:hover,
    .report-nav a:focus {
        color: #111827;
        border-color: rgba(182, 139, 44, 0.65);
        background: #fffaf0;
    }

    .status-bucket {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }

    .status-bucket div {
        padding: 12px;
        border: 1px solid #dfe4ea;
        border-radius: 8px;
        background: #ffffff;
    }

    .status-bucket span {
        display: block;
        color: #64748b;
        font-size: 0.74rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .status-bucket strong {
        color: #111827;
        font-size: 1.45rem;
    }

    .trend-chart {
        display: grid;
        gap: 10px;
    }

    .trend-row {
        display: grid;
        grid-template-columns: minmax(92px, 150px) 1fr 58px;
        align-items: center;
        gap: 10px;
        color: #334155;
        font-size: 0.9rem;
    }

    .trend-bar {
        height: 12px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .trend-bar span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #17446a, #b68b2c);
    }

    .report-header,
    .print-header {
        display: none;
        margin-bottom: 10px;
        padding-bottom: 8px;
        text-align: center;
        font-family: Georgia, "Times New Roman", serif;
        color: #111827;
        border-bottom: 2px solid #111827;
        position: relative;
    }

    .report-header::after,
    .print-header::after {
        content: "";
        display: block;
        border-bottom: 1px solid #b68b2c;
        margin-top: 2px;
    }

    .print-header .document-kicker {
        font-size: 9pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .report-header .org-name,
    .print-header h1 {
        margin: 1mm 0;
        font-size: 15pt;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .report-header .date-range,
    .print-header .document-place,
    .print-header .document-range {
        margin: 0;
        font-size: 9pt;
        font-weight: 700;
    }

    .report-header .report-title,
    .print-header .document-title {
        margin-top: 3mm;
        font-size: 12pt;
        font-weight: 900;
        text-decoration: underline;
        text-transform: uppercase;
    }

    .ai-report-panel {
        margin: 18px 0 22px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #ffffff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
        color: #111827;
        overflow: hidden;
    }

    .ai-report-panel .card-body {
        padding: 18px;
    }

    .ai-report-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e5e7eb;
    }

    .ai-report-header h2 {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.02rem;
        font-weight: 800;
    }

    .ai-report-header p {
        margin: 4px 0 0;
        color: #64748b;
    }

    .ai-insight-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }

    .ai-insight-tile {
        min-height: 104px;
        padding: 13px;
        border: 1px solid #dfe4ea;
        border-radius: 8px;
        background: #f8fafc;
    }

    .ai-insight-tile i {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #17446a;
        background: #eef5fb;
        margin-bottom: 10px;
    }

    .ai-insight-tile span,
    .ai-action-list span {
        display: block;
        color: #64748b;
        font-size: 0.74rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .ai-insight-tile strong {
        display: block;
        color: #111827;
        font-size: 1rem;
    }

    .ai-action-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .ai-action-card {
        border: 1px solid #dfe4ea;
        border-radius: 8px;
        padding: 12px;
        background: #ffffff;
    }

    .ai-action-card.alert-card {
        border-color: rgba(185, 28, 28, 0.24);
        background: #fff7f7;
    }

    .ai-action-card ul {
        margin: 8px 0 0;
        padding-left: 18px;
    }

    .ai-action-card li {
        margin-bottom: 6px;
        color: #334155;
    }

    @media (max-width: 768px) {
        .section-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .ai-insight-grid,
        .ai-action-list,
        .status-bucket {
            grid-template-columns: 1fr;
        }

        .summary-card-grid,
        .report-category-grid,
        .analytics-layout,
        .analytics-layout.equal {
            grid-template-columns: 1fr;
        }

        .trend-row {
            grid-template-columns: 1fr;
            gap: 5px;
        }
    }

    @media (min-width: 769px) and (max-width: 1320px) {
        .summary-card-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .report-category-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .analytics-layout,
        .analytics-layout.equal {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        @page {
            size: A4;
            margin: 10mm 9mm;
        }

        html,
        body {
            background: #fff !important;
            color: #111827 !important;
            font-family: Georgia, "Times New Roman", serif !important;
            font-size: 8.5pt !important;
            line-height: 1.2 !important;
        }

        .user-sidebar,
        .admin-sidebar,
        .premium-admin-sidebar,
        .breadcrumb,
        .back-button,
        .filter-panel,
        .report-nav,
        .ai-assistant-widget,
        .floating-language,
        .no-print,
        .btn,
        .reports-hero,
        .alert,
        .breadcrumb,
        .back-button {
            display: none !important;
        }

        .premium-admin-content,
        .admin-content,
        .reports-page {
            margin: 0 !important;
            max-width: none !important;
            padding: 0 !important;
        }

        .report-header,
        .print-header {
            display: block;
        }

        .formal-report-title {
            display: block;
            margin: 0 0 6mm;
            text-align: center;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 9pt;
            font-weight: 700;
        }

        .reports-page {
            max-width: none !important;
        }

        .report-section {
            margin-top: 3mm !important;
            padding-top: 0 !important;
            break-inside: auto;
        }

        .section-heading {
            display: block;
            margin: 0 0 3mm !important;
            padding: 0 0 1.5mm !important;
            border-bottom: 1.5px solid #111827 !important;
        }

        .section-heading h2 {
            display: block !important;
            font-family: Georgia, "Times New Roman", serif !important;
            font-size: 10pt !important;
            font-weight: 900 !important;
            text-transform: uppercase;
            letter-spacing: 0 !important;
        }

        .section-heading h2::before {
            min-width: 0;
            margin-right: 4px;
        }

        .section-heading h2 i,
        .reports-title i,
        .card-title i,
        .metric-icon,
        .open-icon,
        .ai-report-header i,
        .ai-insight-tile i {
            display: none !important;
        }

        .section-heading span {
            display: block;
            margin-top: 1mm;
            color: #333 !important;
            font-size: 7.8pt !important;
            text-align: left !important;
        }

        .section-note {
            color: #555 !important;
            font-size: 7.2pt !important;
            margin: 0 0 2mm !important;
        }

        .summary-card-grid,
        .report-category-grid,
        .analytics-layout,
        .analytics-layout.equal,
        .analytics-layout.single,
        .ai-insight-grid,
        .ai-action-list,
        .status-bucket {
            display: block !important;
            height: auto !important;
            min-height: 0 !important;
            gap: 0 !important;
            margin-bottom: 4mm !important;
        }

        .summary-card-grid {
            display: none !important;
        }

        .report-category-grid {
            display: block !important;
            counter-reset: report-category;
        }

        .report-category-card {
            counter-increment: report-category;
            display: grid !important;
            grid-template-columns: 8mm 1fr !important;
            min-height: 0 !important;
            margin: 0 0 2mm !important;
            padding: 0 0 1.8mm !important;
            border: 0 !important;
            border-bottom: 1px solid #d7d7d7 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            color: #111827 !important;
            text-decoration: none !important;
            break-inside: avoid;
        }

        .report-category-card::before {
            content: counter(report-category, upper-alpha) ".";
            font-weight: 900;
        }

        .report-category-card h3 {
            font-family: Georgia, "Times New Roman", serif !important;
            font-size: 8.8pt !important;
            font-weight: 900 !important;
            margin: 0 0 .5mm !important;
        }

        .report-category-card p {
            color: #333 !important;
            font-size: 7.5pt !important;
            line-height: 1.15 !important;
        }

        .card,
        .reports-hero,
        .report-card,
        .analytics-card,
        .ai-report-panel,
        .ai-insight-tile,
        .ai-action-card,
        .h-100,
        .status-bucket div {
            height: auto !important;
            min-height: 0 !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: #fff !important;
            color: #111827 !important;
            break-inside: avoid;
            margin-bottom: 3mm !important;
            padding: 0 !important;
        }

        .card,
        .reports-hero,
        .report-card,
        .ai-report-panel {
            break-inside: avoid;
            box-shadow: none !important;
        }

        .card-body,
        .report-card .card-body,
        .ai-report-panel .card-body,
        .summary-card-grid .card-body {
            padding: 1mm 0 !important;
            min-height: 0 !important;
        }

        /* Long report tables must flow into the available page space instead
           of moving the entire card to the following sheet. */
        .report-section .report-card,
        .report-section .report-card .card-body,
        .report-section .table-responsive {
            break-inside: auto !important;
            page-break-inside: auto !important;
        }

        .section-heading {
            break-after: avoid-page;
            page-break-after: avoid;
        }

        .table-responsive {
            display: block !important;
            overflow: visible !important;
            height: auto !important;
            min-height: 0 !important;
        }

        .report-section table.clean-table,
        .report-section .clean-table {
            display: table !important;
            table-layout: auto !important;
        }

        .report-section .clean-table thead {
            display: table-header-group !important;
        }

        .report-section .clean-table tbody {
            display: table-row-group !important;
        }

        .report-section .clean-table tr {
            display: table-row !important;
            break-inside: avoid !important;
            page-break-inside: avoid !important;
        }

        .report-section .clean-table th,
        .report-section .clean-table td {
            display: table-cell !important;
            width: auto !important;
            white-space: normal !important;
        }

        .trend-chart,
        .progress,
        canvas,
        .chart-placeholder {
            display: none !important;
            height: 0 !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .analytics-card {
            min-height: 0 !important;
        }

        .metric-label,
        .status-bucket span,
        .ai-insight-tile span,
        .ai-action-list span {
            color: #333 !important;
            font-size: 7.2pt !important;
            font-weight: 900 !important;
            letter-spacing: 0 !important;
        }

        .metric-value,
        .status-bucket strong {
            margin-top: 1mm !important;
            color: #111827 !important;
            font-family: Georgia, "Times New Roman", serif !important;
            font-size: 13pt !important;
            font-weight: 900 !important;
        }

        .report-card .card-title,
        .ai-report-header h2 {
            display: block !important;
            margin: 0 0 2mm !important;
            padding-bottom: 1mm;
            border-bottom: 1px solid #d7d7d7;
            font-family: Georgia, "Times New Roman", serif !important;
            font-size: 8.8pt !important;
            font-weight: 900 !important;
            text-transform: uppercase;
        }

        .clean-table {
            width: 100% !important;
            border-collapse: collapse !important;
            font-size: 7.5pt !important;
        }

        .overview-table {
            width: 60% !important;
            border-collapse: collapse !important;
            font-size: 8.2pt !important;
            margin: 2mm 0 !important;
        }

        .overview-table td {
            border: 1px solid #999 !important;
            padding: 1.2mm 2mm !important;
        }

        .overview-table td:first-child {
            font-weight: 900 !important;
            width: 60%;
        }

        .clean-table thead th {
            background: #fff !important;
            color: #111827 !important;
            border-top: 1px solid #111827 !important;
            border-bottom: 1px solid #111827 !important;
            font-size: 7pt !important;
            letter-spacing: 0 !important;
            padding: 1mm 1.5mm !important;
        }

        .clean-table td {
            color: #111827 !important;
            border-bottom: 1px solid #d7d7d7 !important;
            padding: 1mm 1.5mm !important;
        }

        .badge {
            border: 0 !important;
            background: transparent !important;
            color: #111827 !important;
            padding: 0 !important;
            font-weight: 700 !important;
        }

        .trend-chart {
            gap: 1.8mm !important;
        }

        .trend-row {
            grid-template-columns: 32mm 1fr 12mm !important;
            gap: 2mm !important;
            font-size: 7.5pt !important;
        }

        .trend-bar {
            height: 2.5mm !important;
            border-radius: 0 !important;
            background: #eee !important;
            border: 1px solid #d7d7d7;
        }

        .trend-bar span {
            border-radius: 0 !important;
            background: #444 !important;
        }

        .progress-row {
            padding: 1.5mm 0 !important;
            break-inside: avoid;
        }

        .progress {
            height: 2.2mm !important;
            border-radius: 0 !important;
            background: #eee !important;
        }

        .progress-bar {
            background: #444 !important;
        }

        a[href]::after {
            content: "" !important;
        }
    }
</style>

<div class="container-fluid mt-4">
    <div class="reports-page">
        <?php include '../includes/breadcrumb.php'; ?>
        <?php include '../includes/back_button.php'; ?>

        <div class="row mb-4 no-print">
            <div class="col-md-12">
                <h1 class="mb-2"><i class="fas fa-chart-simple"></i> Analytics Reports</h1>
                <p class="text-muted mb-0">Generate concise professional reports for requests, sacramental records, registrations, announcements, chatbot inquiries, and activity logs.</p>
            </div>
        </div>

        <div class="report-header print-header">
            <div class="document-kicker">Archdiocese of Cotabato</div>
            <h1 class="org-name">San Lorenzo Ruiz Mission Station</h1>
            <p class="document-place">Aleosan, Cotabato</p>
            <div class="report-title document-title"><?php echo e($current_report_title); ?></div>
            <p class="date-range document-range">Reporting Period: <?php echo e(formatDate($from)); ?> to <?php echo e(formatDate($to)); ?></p>
        </div>
        <div class="formal-report-title">Official Parish Analytics Report</div>

        <div class="reports-hero">
            <div class="row align-items-center g-3">
                <div class="col-lg-5">
                    <h2 class="reports-title">
                        <i class="fas <?php echo e($current_report_icon); ?>"></i>
                        <?php echo e($current_report_title); ?>
                    </h2>
                    <p class="reports-subtitle">
                        <?php echo e($current_report_description); ?>
                        Showing <?php echo e($from); ?> to <?php echo e($to); ?>.
                    </p>
                    <?php if ($selected_report): ?>
                        <a class="btn btn-outline-secondary btn-sm mt-3 no-print" href="reports.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    <?php endif; ?>
                </div>
                <div class="col-lg-7">
                    <form method="GET" class="filter-panel" id="reportGeneratorForm">
                        <input type="hidden" name="generate_report" value="1">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4 col-sm-6">
                                <label for="reportType">Report Type</label>
                                <select id="reportType" name="report" class="form-select">
                                    <option value="all" <?php echo ($selected_report === 'all' || $selected_report === '') ? 'selected' : ''; ?>>Complete Analytics Report</option>
                                    <?php foreach ($report_categories as $report_key => $category): ?>
                                        <option value="<?php echo e($report_key); ?>" <?php echo $selected_report === $report_key ? 'selected' : ''; ?>>
                                            <?php echo e($category['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($selected_report): ?>
                                <div class="col-md-4 col-sm-6">
                                    <label for="reportSearch">Search</label>
                                    <input type="search" id="reportSearch" name="q" class="form-control" value="<?php echo e($search); ?>" placeholder="Search report">
                                </div>
                            <?php endif; ?>
                            <div class="<?php echo $selected_report ? 'col-md-2 col-sm-6' : 'col-md-4 col-sm-6'; ?>">
                                <label for="fromDate">From</label>
                                <input type="date" id="fromDate" name="from" class="form-control" value="<?php echo e($from); ?>">
                            </div>
                            <div class="<?php echo $selected_report ? 'col-md-2 col-sm-6' : 'col-md-4 col-sm-6'; ?>">
                                <label for="toDate">To</label>
                                <input type="date" id="toDate" name="to" class="form-control" value="<?php echo e($to); ?>">
                            </div>
                            <div class="col-12">
                                <div class="report-actions">
                                    <button class="btn btn-primary" type="submit" id="generateReportButton"><i class="fas fa-file-lines"></i> Generate Report</button>
                                    <button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                                    <button class="btn btn-outline-danger" type="button" onclick="window.print()"><i class="fas fa-file-pdf"></i> Export PDF</button>
                                    <a class="btn btn-outline-success" href="?<?php echo $selected_report ? 'report=' . urlencode($selected_report) . '&' : ''; ?>from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&q=<?php echo urlencode($search); ?>&export=csv">
                                        <i class="fas fa-file-export"></i> CSV
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!$selected_report): ?>
        <div class="section-heading">
            <h2 data-section="I."><i class="fas fa-folder-open"></i> Report Categories</h2>
            <span>Select one report to view its complete details</span>
        </div>

        <div class="report-category-grid">
            <?php foreach ($report_categories as $report_key => $category): ?>
                <a class="report-category-card" href="reports.php?report=<?php echo urlencode($report_key); ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
                    <span class="metric-icon"><i class="fas <?php echo e($category['icon']); ?>"></i></span>
                    <div>
                        <h3><?php echo e($category['title']); ?></h3>
                        <p><?php echo e($category['description']); ?></p>
                    </div>
                    <i class="fas fa-arrow-right open-icon"></i>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="section-heading">
            <h2 data-section="II."><i class="fas fa-gauge-high"></i> Overview</h2>
            <span>Key totals for the selected date range</span>
        </div>

        <div class="summary-card-grid">
        <?php
        $cards = [
            ['Total Registered Parishioners', $summary['total_users'], 'fa-users', 'reports.php?report=registrations&from=' . urlencode($from) . '&to=' . urlencode($to)],
            ['Total Certificate Requests', $summary['requests'], 'fa-certificate', 'reports.php?report=requests&from=' . urlencode($from) . '&to=' . urlencode($to)],
            ['Approved Requests', fetchCount($conn, "SELECT COUNT(*) AS count FROM requests WHERE deleted_at IS NULL AND status = 'approved' AND date_requested BETWEEN '$from_sql' AND '$to_sql'"), 'fa-circle-check', 'reports.php?report=requests&q=approved&from=' . urlencode($from) . '&to=' . urlencode($to)],
            ['Pending Requests', $summary['pending_requests'], 'fa-hourglass-half', 'reports.php?report=requests&q=pending&from=' . urlencode($from) . '&to=' . urlencode($to)],
            ['Total Sacramental Records', $summary['records'], 'fa-book-bible', 'reports.php?report=records&from=' . urlencode($from) . '&to=' . urlencode($to)],
            ['AI Chatbot Inquiries', $summary['chatbot_interactions'], 'fa-robot', 'reports.php?report=chatbot&from=' . urlencode($from) . '&to=' . urlencode($to)]
        ];
        ?>
        <?php foreach ($cards as $card): ?>
            <a href="<?php echo e($card[3]); ?>" class="card analytics-card h-100" aria-label="Open <?php echo e($card[0]); ?>">
                <div class="card-body">
                    <div class="metric-icon"><i class="fas <?php echo $card[2]; ?>"></i></div>
                    <div>
                        <div class="metric-label"><?php echo e($card[0]); ?></div>
                        <div class="metric-value"><?php echo number_format($card[1]); ?></div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        </div>

        <section class="card ai-report-panel">
            <div class="card-body">
                <div class="ai-report-header">
                    <div>
                        <h2><i class="fas fa-robot"></i> TUGON AI Report Insights</h2>
                        <p>Automatically summarizes trends, workload risk, and recommended admin actions from this report.</p>
                    </div>
                </div>

                <div class="ai-insight-grid">
                    <?php foreach ($ai_report['insights'] as $insight): ?>
                        <div class="ai-insight-tile">
                            <i class="fas <?php echo e($insight['icon']); ?>"></i>
                            <span><?php echo e($insight['label']); ?></span>
                            <strong><?php echo e($insight['value']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="ai-action-list">
                    <div class="ai-action-card <?php echo !empty($ai_report['alerts']) ? 'alert-card' : ''; ?>">
                        <span><i class="fas fa-triangle-exclamation"></i> AI Alerts</span>
                        <ul>
                            <?php if (!empty($ai_report['alerts'])): ?>
                                <?php foreach ($ai_report['alerts'] as $alert): ?>
                                    <li><?php echo e($alert); ?></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li>No urgent risk detected for this date range.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="ai-action-card">
                        <span><i class="fas fa-lightbulb"></i> Recommended Actions</span>
                        <ul>
                            <?php foreach ($ai_report['recommendations'] as $recommendation): ?>
                                <li><?php echo e($recommendation); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($selected_report === 'all'): ?>
        <div class="report-section" id="activity-log-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'activity', 'I.')); ?>"><i class="fas fa-shield-halved"></i> Activity Logs Section</h2>
                <span class="section-note">Important account, request, record, and announcement actions with date and time</span>
            </div>
            <div class="analytics-layout single">
                <div class="card report-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-shield-halved"></i> Audit Logs <span class="text-muted small">(top <?php echo intval($report_row_limit); ?>)</span></h5>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle clean-table keep-table">
                                <thead><tr><th>User Name</th><th>Role</th><th>Action Performed</th><th>Date</th><th>Time</th><th>Table</th><th>Record ID</th></tr></thead>
                                <tbody>
                                    <?php foreach ($activity_logs as $row): ?>
                                        <tr class="clickable-row" onclick="window.location.href='audit-logs.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>'">
                                            <td><?php echo e($row['admin_name']); ?></td>
                                            <td><?php echo e(ucfirst($row['admin_role'])); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo e(ucwords(strtolower(str_replace('_', ' ', $row['action_name'])))); ?></span></td>
                                            <td><?php echo e(formatDate($row['activity_date'])); ?></td>
                                            <td><?php echo e(formatTime($row['activity_date'])); ?></td>
                                            <td><?php echo e($row['table_name']); ?></td>
                                            <td><?php echo e($row['record_id']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($activity_logs)): ?>
                                        <tr><td colspan="7" class="text-muted">No audit logs found for this date range.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (reportIncludes($selected_report, 'requests')): ?>
        <div class="report-section" id="certificate-request-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'requests', 'I.')); ?>"><i class="fas fa-chart-simple"></i> Request Analytics Section</h2>
                <span>Certificate request trends, status counts, and request details</span>
            </div>
            <div class="status-bucket">
                <?php foreach (['pending', 'approved', 'completed', 'rejected'] as $status_label): ?>
                    <div>
                        <span><?php echo e(ucfirst($status_label)); ?></span>
                        <strong><?php echo fetchCount($conn, "SELECT COUNT(*) AS count FROM requests WHERE deleted_at IS NULL AND status = '$status_label' AND date_requested BETWEEN '$from_sql' AND '$to_sql'"); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="analytics-layout">
                <div class="card report-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-chart-line"></i> Certificate Requests by Day</h5>
                        <?php $daily_request_max = max(1, ...array_map('intval', array_column($daily_request_trends, 'count') ?: [1])); ?>
                        <div class="trend-chart">
                            <?php foreach ($daily_request_trends as $row): ?>
                                <div class="trend-row">
                                    <span><?php echo e(formatDate($row['label'])); ?></span>
                                    <div class="trend-bar"><span style="width: <?php echo percentOf($row['count'], $daily_request_max); ?>%;"></span></div>
                                    <strong><?php echo intval($row['count']); ?></strong>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($daily_request_trends)): ?>
                                <div class="empty-state">No request trend data for this date range.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card report-card h-100" id="requests-report">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-chart-pie"></i> Request Status Counts</h5>
                        <?php if (count($request_status) > 0): ?>
                            <?php foreach ($request_status as $row): ?>
                                <?php $pct = percentOf($row['count'], $summary['requests']); ?>
                                <a href="manage-requests.php?status=<?php echo urlencode($row['label']); ?>" class="progress-row">
                                    <div class="progress-label">
                                        <span><?php echo e(ucfirst($row['label'])); ?></span>
                                        <strong><?php echo $row['count']; ?> (<?php echo $pct; ?>%)</strong>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar" style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">No request data for this date range.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card report-card mt-3">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-table-list"></i> Certificate Request Details <span class="text-muted small">(top <?php echo intval($report_row_limit); ?>)</span></h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle clean-table keep-table">
                            <thead><tr><th>ID</th><th>Parishioner</th><th>Certificate Type</th><th>Date</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($certificate_requests as $row): ?>
                                    <tr class="clickable-row" onclick="window.location.href='request-workflow.php?id=<?php echo urlencode($row['request_id']); ?>'">
                                        <td><?php echo e($row['reference_number'] ?: ('#' . $row['request_id'])); ?></td>
                                        <td><?php echo e($row['fullname']); ?></td>
                                        <td><?php echo e(ucfirst(str_replace('_', ' ', $row['request_type']))); ?></td>
                                        <td><?php echo e(formatDate($row['date_requested'])); ?></td>
                                        <td><span class="badge bg-<?php echo getStatusBadgeClass($row['status']); ?>"><?php echo e(ucfirst($row['status'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($certificate_requests) === 0): ?>
                                    <tr><td colspan="5" class="text-muted">No certificate requests found for this date range.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
        <?php endif; ?>

        <?php if (reportIncludes($selected_report, 'records')): ?>
        <div class="report-section" id="sacramental-records-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'records', 'I.')); ?>"><i class="fas fa-book-bible"></i> Sacramental Records Analytics Section</h2>
                <span>Total stored Baptism, First Communion, Confirmation, and Marriage records</span>
            </div>
            <div class="analytics-layout equal">
                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-chart-column"></i> Sacramental Record Distribution</h5>
                            <?php $record_total = max(1, array_sum(array_column($record_totals, 'count'))); ?>
                            <div class="trend-chart">
                                <?php foreach ($record_totals as $row): ?>
                                    <?php $pct = percentOf($row['count'], $record_total); ?>
                                    <div class="trend-row">
                                        <span><?php echo e($row['label']); ?></span>
                                        <div class="trend-bar"><span style="width: <?php echo $pct; ?>%;"></span></div>
                                        <strong><?php echo intval($row['count']); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-table-list"></i> Record Categories</h5>
                            <?php foreach ($record_totals as $row): ?>
                                <?php $pct = percentOf($row['count'], $record_total); ?>
                                <a href="manage-records.php?type=<?php echo urlencode(strtolower(str_replace('First Communion', 'communion', $row['label']))); ?>" class="progress-row">
                                    <div class="progress-label">
                                        <span><?php echo e($row['label']); ?></span>
                                        <strong><?php echo $row['count']; ?> (<?php echo $pct; ?>%)</strong>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card report-card mt-3">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                        <div>
                            <h5 class="card-title mb-1"><i class="fas fa-users-line"></i> Sacramental Record Names and Requesters</h5>
                            <p class="text-muted mb-0">Lists the people included in the selected record totals, including the parishioner who requested the record when available.</p>
                        </div>
                        <span class="badge bg-primary-subtle text-primary"><?php echo count($sacramental_record_rows); ?> records shown</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle clean-table keep-table">
                            <thead>
                                <tr>
                                    <th>Record Type</th>
                                    <th>Name on Record</th>
                                    <th>Requested By</th>
                                    <th>Reference No.</th>
                                    <th>Record Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sacramental_record_rows as $row): ?>
                                    <tr>
                                        <td><span class="badge bg-light text-dark"><?php echo e($row['record_type']); ?></span></td>
                                        <td>
                                            <strong><?php echo e($row['person_name']); ?></strong>
                                            <div class="text-muted small">Registry: <?php echo e($row['registry_no'] ?: ('#' . $row['record_id'])); ?></div>
                                        </td>
                                        <td><?php echo e($row['requested_by']); ?></td>
                                        <td><?php echo $row['reference_number'] ? e($row['reference_number']) : '<span class="text-muted">Manual entry</span>'; ?></td>
                                        <td><?php echo e(formatDate($row['record_date'])); ?></td>
                                        <td><span class="badge bg-<?php echo getStatusBadgeClass($row['status']); ?>"><?php echo e(ucfirst($row['status'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($sacramental_record_rows)): ?>
                                    <tr><td colspan="6" class="text-muted">No sacramental record names found for this date range.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (reportIncludes($selected_report, 'registrations')): ?>
        <div class="report-section" id="registration-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'registrations', 'I.')); ?>"><i class="fas fa-user-check"></i> Parishioner Registration Analytics Section</h2>
                <span>Total registered users, verified accounts, and recent registrations</span>
            </div>
            <div class="analytics-layout">
                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-id-card"></i> Registration Summary</h5>
                            <div class="status-bucket" style="grid-template-columns: 1fr;">
                                <div><span>Total Registered Users</span><strong><?php echo number_format($summary['total_users']); ?></strong></div>
                                <div><span>Verified Accounts</span><strong><?php echo number_format($summary['verified_users']); ?></strong></div>
                                <div><span>Pending Verification</span><strong><?php echo number_format($summary['pending_verification']); ?></strong></div>
                                <div><span>Recent Registrations</span><strong><?php echo number_format($summary['new_users']); ?></strong></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-clock"></i> Recent Registrations <span class="text-muted small">(top <?php echo intval($report_row_limit); ?>)</span></h5>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle clean-table keep-table">
                                    <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Verified</th><th>Registered</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($recent_registrations as $row): ?>
                                            <tr class="clickable-row" onclick="window.location.href='manage-users.php?search=<?php echo urlencode($row['email']); ?>'">
                                                <td><?php echo e($row['fullname']); ?></td>
                                                <td><?php echo e($row['email']); ?></td>
                                                <td><span class="badge bg-<?php echo getStatusBadgeClass($row['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $row['status']))); ?></span></td>
                                                <td><?php echo $row['verified_at'] ? e(formatDateTime($row['verified_at'])) : 'For review'; ?></td>
                                                <td><?php echo e(formatDateTime($row['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($recent_registrations)): ?>
                                            <tr><td colspan="5" class="text-muted">No registrations found for this date range.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (reportIncludes($selected_report, 'chatbot')): ?>
        <div class="report-section" id="chatbot-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'chatbot', 'I.')); ?>"><i class="fas fa-robot"></i> AI Chatbot Inquiry Analytics Section</h2>
                <span>Total interactions, frequently asked questions, and inquiry trends</span>
            </div>
            <div class="analytics-layout">
                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-comments"></i> Frequently Asked Questions</h5>
                            <div class="status-bucket" style="grid-template-columns: 1fr;">
                                <div><span>Total Chatbot Interactions</span><strong><?php echo number_format($summary['chatbot_interactions']); ?></strong></div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm clean-table keep-table">
                                    <thead><tr><th>Question</th><th class="text-end">Asked</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($chatbot_top_questions as $row): ?>
                                            <tr>
                                                <td><?php echo e(mb_strimwidth($row['label'], 0, 90, '...')); ?></td>
                                                <td class="text-end"><strong><?php echo intval($row['count']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($chatbot_top_questions)): ?>
                                            <tr><td colspan="2" class="text-muted">No chatbot inquiries have been recorded for this range.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-chart-column"></i> Inquiry Trends</h5>
                            <?php $chatbot_max = max(1, ...array_map('intval', array_column($chatbot_trends, 'count') ?: [1])); ?>
                            <div class="trend-chart">
                                <?php foreach ($chatbot_trends as $row): ?>
                                    <?php $pct = percentOf($row['count'], $chatbot_max); ?>
                                    <div class="trend-row">
                                        <span><?php echo e(formatDate($row['label'])); ?></span>
                                        <div class="trend-bar"><span style="width: <?php echo $pct; ?>%;"></span></div>
                                        <strong><?php echo intval($row['count']); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($chatbot_trends)): ?>
                                    <div class="empty-state">No chatbot trend data yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_report === 'requests'): ?>
        <div class="report-section" id="transaction-trends-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'transaction_trends', 'II.')); ?>"><i class="fas fa-chart-line"></i> Monthly and Yearly Transaction Reports</h2>
                <span>Charts, summaries, and request transaction trends by selected date range</span>
            </div>
            <div class="analytics-layout equal">
                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-calendar-days"></i> Monthly Trends</h5>
                            <?php $monthly_max = max(1, ...array_map('intval', array_column($monthly_trends, 'count') ?: [1])); ?>
                            <div class="trend-chart">
                                <?php foreach ($monthly_trends as $row): ?>
                                    <div class="trend-row">
                                        <span><?php echo e($row['label']); ?></span>
                                        <div class="trend-bar"><span style="width: <?php echo percentOf($row['count'], $monthly_max); ?>%;"></span></div>
                                        <strong><?php echo intval($row['count']); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($monthly_trends)): ?>
                                    <div class="empty-state">No monthly transaction data for this range.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="card report-card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-calendar"></i> Yearly Trends</h5>
                            <?php $yearly_max = max(1, ...array_map('intval', array_column($yearly_trends, 'count') ?: [1])); ?>
                            <div class="trend-chart">
                                <?php foreach ($yearly_trends as $row): ?>
                                    <div class="trend-row">
                                        <span><?php echo e($row['label']); ?></span>
                                        <div class="trend-bar"><span style="width: <?php echo percentOf($row['count'], $yearly_max); ?>%;"></span></div>
                                        <strong><?php echo intval($row['count']); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($yearly_trends)): ?>
                                    <div class="empty-state">No yearly transaction data for this range.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (reportIncludes($selected_report, 'announcements')): ?>
        <div class="report-section" id="announcements-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'announcements', 'I.')); ?>"><i class="fas fa-bullhorn"></i> Announcements Section</h2>
                <span>Posted parish announcements with dates and target audience</span>
            </div>
            <div class="analytics-layout equal">
        <div>
            <div class="card report-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-bullhorn"></i> Announcement Types</h5>
                    <table class="table table-sm clean-table keep-table">
                        <thead><tr><th>Type</th><th class="text-end">Count</th></tr></thead>
                        <tbody>
                            <?php foreach ($announcement_types as $row): ?>
                                <tr class="clickable-row" onclick="window.location.href='manage-announcements.php?type=<?php echo urlencode($row['label']); ?>'"><td><?php echo e(ucfirst($row['label'])); ?></td><td class="text-end"><strong><?php echo $row['count']; ?></strong></td></tr>
                            <?php endforeach; ?>
                            <?php if (count($announcement_types) === 0): ?>
                                <tr><td colspan="2" class="text-muted">No announcement data for this range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div>
            <div class="card report-card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-list"></i> Posted Announcements <span class="text-muted small">(top <?php echo intval($report_row_limit); ?>)</span></h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle clean-table keep-table">
                            <thead><tr><th>Title</th><th>Date</th><th>Target Audience</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($announcement_rows as $row): ?>
                                    <tr class="clickable-row" onclick="window.location.href='manage-announcements.php?type=<?php echo urlencode($row['type']); ?>'">
                                        <td><?php echo e($row['title']); ?><br><small class="text-muted"><?php echo e(ucfirst($row['type'])); ?> by <?php echo e($row['posted_by']); ?></small></td>
                                        <td><?php echo e(formatDateTime($row['published_date'])); ?></td>
                                        <td>All Parishioners</td>
                                        <td><span class="badge bg-<?php echo getStatusBadgeClass($row['status']); ?>"><?php echo e(ucfirst($row['status'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($announcement_rows) === 0): ?>
                                    <tr><td colspan="4" class="text-muted">No announcements found for this range.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_report === 'activity'): ?>
        <div class="report-section" id="activity-log-report">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'activity', 'I.')); ?>"><i class="fas fa-shield-halved"></i> Activity Logs Section</h2>
                <span>Important account, request, record, and announcement actions with date and time</span>
            </div>
            <div class="analytics-layout single">
                <div class="card report-card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-shield-halved"></i> Audit Logs <span class="text-muted small">(top <?php echo intval($report_row_limit); ?>)</span></h5>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle clean-table keep-table">
                                <thead><tr><th>User Name</th><th>Role</th><th>Action Performed</th><th>Date</th><th>Time</th><th>Table</th><th>Record ID</th></tr></thead>
                                <tbody>
                                    <?php foreach ($activity_logs as $row): ?>
                                        <tr class="clickable-row" onclick="window.location.href='audit-logs.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>'">
                                            <td><?php echo e($row['admin_name']); ?></td>
                                            <td><?php echo e(ucfirst($row['admin_role'])); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo e(ucwords(strtolower(str_replace('_', ' ', $row['action_name'])))); ?></span></td>
                                            <td><?php echo e(formatDate($row['activity_date'])); ?></td>
                                            <td><?php echo e(formatTime($row['activity_date'])); ?></td>
                                            <td><?php echo e($row['table_name']); ?></td>
                                            <td><?php echo e($row['record_id']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($activity_logs)): ?>
                                        <tr><td colspan="7" class="text-muted">No audit logs found for this date range.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_report): ?>
        <div class="report-section formal-metrics-table" id="overview-key-metrics">
            <div class="section-heading">
                <h2 data-section="<?php echo e(reportSectionNumber($selected_report, 'overview', ($selected_report === 'requests' ? 'III.' : 'II.'))); ?>"><i class="fas fa-list-check"></i> Overview / Key Metrics</h2>
                <span>Summary totals for the selected report period</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm clean-table keep-table overview-table">
                    <thead>
                        <tr><th>Metric</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Total Registered Parishioners</td><td class="text-end"><?php echo number_format($summary['total_users']); ?></td></tr>
                        <tr><td>Total Certificate Requests</td><td class="text-end"><?php echo number_format($summary['requests']); ?></td></tr>
                        <tr><td>Pending Requests</td><td class="text-end"><?php echo number_format($summary['pending_requests']); ?></td></tr>
                        <tr><td>Completed Requests</td><td class="text-end"><?php echo number_format($summary['completed_requests']); ?></td></tr>
                        <tr><td>Total Sacramental Records</td><td class="text-end"><?php echo number_format($summary['records']); ?></td></tr>
                        <tr><td>AI Chatbot Inquiries</td><td class="text-end"><?php echo number_format($summary['chatbot_interactions']); ?></td></tr>
                        <tr><td>Announcements Posted</td><td class="text-end"><?php echo number_format($summary['announcements']); ?></td></tr>
                        <tr><td>Audit Log Entries</td><td class="text-end"><?php echo number_format($summary['audit_logs']); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('reportGeneratorForm');
    const button = document.getElementById('generateReportButton');
    if (form && button) {
        form.addEventListener('submit', function() {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        });
    }
});
</script>

<?php include '../templates/footer.php'; ?>
