<?php
/**
 * Manage Requests Page
 * Admin interface for managing user requests and sacramental reservations
 */

// Send no-cache headers so one admin's results are never cached for another
if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Include centralized session management
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/ReservationService.php';

// Require admin access
requireAdmin();
requirePermission('requests.manage');
if (!hasAnyPermission(['requests.manage', 'reservations.manage'])) {
    redirect('dashboard.php');
}
ensureEmailNotificationSchema($conn);
ensureRequestDocumentsSchema($conn);
ensureRequestPaymentsSchema($conn);
ensureCertificateDuplicateGuardSchema($conn);
ensureRequestMatchingSchema($conn);

$error = '';
$success = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requireValidCsrfToken();

// Ensure Request Archive Column Function - Documents this helper's role in the parish management workflow.
if (!function_exists('ensureRequestArchiveColumn')) {
    function ensureRequestArchiveColumn($conn) {
        return columnExists($conn, 'requests', 'deleted_at');
    }
}

// Helper to construct filter URLs preserving active parameters
if (!function_exists('adminRequestsUrl')) {
    function adminRequestsUrl(array $params = []): string {
        global $service_date;
        $activeServiceDate = isset($service_date) ? $service_date : '';
        if ($activeServiceDate === '' && isset($_GET['service_date'])) {
            $rawDate = trim((string) $_GET['service_date']);
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $rawDate) || $rawDate === 'this_week') {
                $activeServiceDate = $rawDate;
            }
        }
        $current = [
            'status' => $_GET['status'] ?? '',
            'type' => $_GET['type'] ?? '',
            'service_date' => $activeServiceDate,
            'q' => $_GET['q'] ?? '',
            'page' => $_GET['page'] ?? ''
        ];
        $merged = array_merge($current, $params);
        $clean = [];
        foreach ($merged as $k => $v) {
            $vStr = trim((string) $v);
            if ($vStr !== '') {
                $clean[$k] = $vStr;
            }
        }
        return 'manage-requests.php' . (!empty($clean) ? '?' . http_build_query($clean) : '');
    }
}

// Request Category Helpers - Keep admin filtering readable while preserving existing request values.
if (!function_exists('adminRequestTypeGroups')) {
    function adminRequestTypeGroups() {
        return [
            'certificate' => [
                'label' => 'Certificate',
                'types' => [
                    'baptismal_certificate', 'baptism_certification',
                    'confirmation_certificate', 'confirmation_certification',
                    'first_communion_certificate', 'first_communion_certification',
                    'marriage_certification', 'funeral_certification'
                ]
            ],
            'blessing' => [
                'label' => 'Blessing',
                'types' => ['house_blessing', 'car_blessing', 'vehicle_blessing', 'business_blessing', 'office_blessing', 'event_blessing', 'other_blessing']
            ],
            'sacramental' => [
                'label' => 'Sacramental Services',
                'types' => [
                    'baptism_service',
                    'first_communion_service',
                    'first_communion',
                    'communion',
                    'confirmation_service',
                    'confirmation',
                    'marriage_wedding_service',
                    'funeral_mass',
                    'anointing_of_the_sick',
                    'patronal_fiesta',
                    'church_reservation',
                    'wedding_reservation',
                    'burial_reservation',
                    'wedding',
                    'baptism',
                    'burial',
                    'church_venue'
                ]
            ],
        ];
    }
}

if (!function_exists('adminRequestCategorySql')) {
    function adminRequestCategorySql($column) {
        $cases = [];
        foreach (adminRequestTypeGroups() as $category => $group) {
            $quoted = array_map(function ($type) {
                return "'" . addslashes($type) . "'";
            }, $group['types']);
            $cases[] = "WHEN $column IN (" . implode(',', $quoted) . ") THEN '$category'";
        }
        return 'CASE ' . implode(' ', $cases) . " ELSE 'other' END";
    }
}

if (!function_exists('adminRequestCategoryLabel')) {
    function adminRequestCategoryLabel($category) {
        $groups = adminRequestTypeGroups();
        return $groups[$category]['label'] ?? 'Other';
    }
}

if (!ensureRequestArchiveColumn($conn)) {
    $error = 'Error preparing request archive: ' . $conn->error;
}

// Handle status update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_reservation') {
    $reservation_id = intval($_POST['reservation_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $admin_notes = trim($_POST['admin_response'] ?? '');
    $allowed_reservation_statuses = ['pending', 'approved', 'rejected', 'cancelled'];

    if ($reservation_id <= 0 || !in_array($status, $allowed_reservation_statuses, true)) {
        $error = 'Please choose a valid reservation status.';
    } else {
        try {
            $result=(new ReservationService($conn))->changeStatus($reservation_id,$status,(int)$_SESSION['user_id'],$admin_notes);
            $success='Reservation updated successfully. '.$result['calendar']['message'];
        } catch(Throwable $e) { $error=$e->getMessage(); }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST' && ($_POST['action'] ?? '') == 'archive_request') {
    $request_id = intval($_POST['request_id']);

    if ($conn->query("UPDATE requests SET deleted_at = NOW() WHERE request_id = $request_id")) {
        createAuditLog($conn, $_SESSION['user_id'], 'ARCHIVE_REQUEST', 'requests', $request_id);
        $success = 'Request archived successfully!';
    } else {
        $error = 'Error archiving request: ' . $conn->error;
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST' && isset($_POST['request_id'])) {
    $request_id = intval($_POST['request_id']);
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $admin_response = $conn->real_escape_string($_POST['admin_response'] ?? '');
    $allowed_statuses = ['pending', 'processing', 'completed', 'rejected'];

    if (!in_array($status, $allowed_statuses, true)) {
        $error = 'Invalid request status. Allowed: Pending, Processing, Completed, Rejected.';
    } elseif ($status === 'completed') {
        require_once __DIR__ . '/../services/SacramentalApprovalService.php';
        $req_type_row = $conn->query("SELECT request_type FROM requests WHERE request_id = $request_id")->fetch_assoc();
        $req_type = (string)($req_type_row['request_type'] ?? '');
        $is_sacramental = SacramentalApprovalService::isSacramentalRequestType($req_type);

        if ($is_sacramental) {
            try {
                $sacramentalService = new SacramentalApprovalService($conn);
                $completionResult = $sacramentalService->completeRequest($request_id, (int)$_SESSION['user_id'], [
                    'admin_response' => $admin_response,
                    'ceremony_date' => !empty($_POST['ceremony_date']) ? trim((string)$_POST['ceremony_date']) : null,
                    'target_status' => 'completed'
                ]);
                $success = 'Request marked as completed! ' . (!empty($completionResult['sacramental_record']['registered']) ? 'Sacramental record registered and calendar schedule locked.' : 'Calendar schedule locked.');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } else {
            $sql = "UPDATE requests SET status = 'completed', admin_response = '$admin_response', updated_at = NOW() WHERE request_id = $request_id";
            if ($conn->query($sql)) {
                $req_result = $conn->query("SELECT r.user_id, r.reference_number, r.request_type, u.email, u.fullname, COALESCE(np.email_enabled, 1) AS email_enabled
                    FROM requests r
                    JOIN users u ON r.user_id = u.id
                    LEFT JOIN notification_preferences np ON np.user_id = u.id AND np.category = 'requests'
                    WHERE r.request_id = $request_id");
                $req_data = $req_result->fetch_assoc();
                if ($req_data) {
                    createRequestStatusNotification($conn, $req_data, 'completed', $admin_response);
                }
                createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_REQUEST', 'requests', $request_id);
                syncApprovedRequestToCalendar($conn, $request_id, (int)$_SESSION['user_id']);
                $success = 'Request marked as completed and calendar schedule updated!';
            } else {
                $error = 'Error updating request: ' . $conn->error;
            }
        }
    } else {
        $sql = "UPDATE requests SET status = '$status', admin_response = '$admin_response'
                WHERE request_id = $request_id";

        if ($conn->query($sql)) {
            // Get user_id for notification
            $req_result = $conn->query("SELECT r.user_id, r.reference_number, r.request_type, u.email, u.fullname, COALESCE(np.email_enabled, 1) AS email_enabled
                FROM requests r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN notification_preferences np ON np.user_id = u.id AND np.category = 'requests'
                WHERE r.request_id = $request_id");
            $req_data = $req_result->fetch_assoc();
            
            if ($req_data) {
                createRequestStatusNotification($conn, $req_data, $status, $admin_response);
            }
            createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_REQUEST', 'requests', $request_id);
            if (in_array($status, ['pending', 'processing', 'rejected'], true)) {
                cancelLinkedRequestCalendarEvent($conn, $request_id);
            }
            $success = 'Request updated successfully!';
        } else {
            $error = 'Error updating request: ' . $conn->error;
        }
    }
}

// Manila timezone calculations for quick chips and comparisons
$tz = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $tz);
$today_str = $now->format('Y-m-d');
$tomorrow_str = (clone $now)->modify('+1 day')->format('Y-m-d');
$dayOfWeek = (int) $now->format('N'); // 1 (Mon) to 7 (Sun)
$weekStartDt = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days');
$weekEndDt = (clone $weekStartDt)->modify('+6 days');
$week_start_str = $weekStartDt->format('Y-m-d');
$week_end_str = $weekEndDt->format('Y-m-d');

// Get filter parameters
$status_filter = strtolower(trim((string) ($_GET['status'] ?? '')));
$type_filter = strtolower(trim((string) ($_GET['type'] ?? $_GET['category'] ?? '')));
$search = trim($_GET['q'] ?? '');
$service_date = trim((string) ($_GET['service_date'] ?? ''));

$type_groups = adminRequestTypeGroups();
$type_filter = isset($type_groups[$type_filter]) ? $type_filter : '';

// Validate service_date (YYYY-MM-DD or 'this_week')
$is_valid_date = false;
$is_week_filter = ($service_date === 'this_week');

if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $service_date)) {
    $dateParts = explode('-', $service_date);
    if (checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
        $is_valid_date = true;
    }
}

// Reject any invalid format safely
if (!$is_valid_date && !$is_week_filter) {
    $service_date = '';
}

// Category behavior: If Category is Certificates, service date does not apply
if ($type_filter === 'certificate') {
    $service_date = '';
    $is_valid_date = false;
    $is_week_filter = false;
}

$show_certificate_note = false;

$request_category_sql = adminRequestCategorySql('r.request_type');
$reservation_category_sql = adminRequestCategorySql('r.reservation_type');

$request_where = [
    "r.deleted_at IS NULL",
    "NOT EXISTS (SELECT 1 FROM reservations linked_reservation WHERE linked_reservation.request_id=r.request_id)"
];
$reservation_where = ["1=1"];

if (!empty($status_filter) && in_array($status_filter, ['pending', 'processing', 'completed', 'rejected'], true)) {
    $status_safe = $conn->real_escape_string($status_filter);
    $request_where[] = "r.status = '$status_safe'";
    $reservation_where[] = "r.status = '$status_safe'";
}

if (!empty($type_filter)) {
    $type_filter_safe = $conn->real_escape_string($type_filter);
    $request_where[] = "($request_category_sql) = '$type_filter_safe'";
    $reservation_where[] = "($reservation_category_sql) = '$type_filter_safe'";
}

if ($search !== '') {
    $search_safe = $conn->real_escape_string('%' . $search . '%');
    $request_where[] = "(r.reference_number LIKE '$search_safe' OR r.request_type LIKE '$search_safe' OR r.description LIKE '$search_safe' OR r.admin_response LIKE '$search_safe' OR u.fullname LIKE '$search_safe' OR u.email LIKE '$search_safe')";
    $reservation_where[] = "(CONCAT('RES-', LPAD(r.reservation_id, 6, '0')) LIKE '$search_safe' OR r.reservation_type LIKE '$search_safe' OR r.event_details LIKE '$search_safe' OR r.admin_notes LIKE '$search_safe' OR u.fullname LIKE '$search_safe' OR u.email LIKE '$search_safe')";
}

// Service Date Filter Application
if ($service_date !== '') {
    $request_where[] = "LOWER(r.request_type) NOT IN ('first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation')";
    $reservation_where[] = "LOWER(r.reservation_type) NOT IN ('first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation')";
    if ($type_filter === '') {
        // "All Categories" with active date filter: exclude certificates
        $request_where[] = "($request_category_sql) IN ('blessing', 'sacramental')";
        $show_certificate_note = true;
    } elseif ($type_filter === 'blessing') {
        // Reservations table only has sacramental items
        $reservation_where[] = "1=0";
    }

    if ($is_valid_date) {
        $service_date_safe = $conn->real_escape_string($service_date);
        $next_day = date('Y-m-d', strtotime($service_date . ' +1 day'));
        $next_day_safe = $conn->real_escape_string($next_day);

        // Direct date range comparison for prepared index utilization without wrapping column in function
        $request_where[] = "((s_locks.slot_date >= '$service_date_safe' AND s_locks.slot_date < '$next_day_safe') OR (s_locks.slot_date IS NULL AND se.event_date >= '$service_date_safe' AND se.event_date < '$next_day_safe'))";
        $reservation_where[] = "(r.event_date >= '$service_date_safe' AND r.event_date < '$next_day_safe')";
    } elseif ($is_week_filter) {
        $week_start_safe = $conn->real_escape_string($week_start_str);
        $week_next_day = date('Y-m-d', strtotime($week_end_str . ' +1 day'));
        $week_next_safe = $conn->real_escape_string($week_next_day);

        $request_where[] = "((s_locks.slot_date >= '$week_start_safe' AND s_locks.slot_date < '$week_next_safe') OR (s_locks.slot_date IS NULL AND se.event_date >= '$week_start_safe' AND se.event_date < '$week_next_safe'))";
        $reservation_where[] = "(r.event_date >= '$week_start_safe' AND r.event_date < '$week_next_safe')";
    }
}

$request_where_sql = implode(' AND ', $request_where);
$reservation_where_sql = implode(' AND ', $reservation_where);

$page = intval($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$limit = 10;

$has_record_holder_col = columnExists($conn, 'requests', 'record_holder_name');
$has_matched_id_col = columnExists($conn, 'requests', 'matched_record_id');
$has_matched_type_col = columnExists($conn, 'requests', 'matched_record_type');
$has_match_status_col = columnExists($conn, 'requests', 'match_status');
$has_match_details_col = columnExists($conn, 'requests', 'match_details');

$record_holder_select = $has_record_holder_col ? "r.record_holder_name" : "NULL";
$matched_id_select = $has_matched_id_col ? "r.matched_record_id" : "NULL";
$matched_type_select = $has_matched_type_col ? "r.matched_record_type" : "NULL";
$match_status_select = $has_match_status_col ? "r.match_status" : "'unmatched'";
$match_details_select = $has_match_details_col ? "r.match_details" : "NULL";

$request_select = "
    SELECT
        'request' AS item_source,
        r.request_id AS item_id,
        r.reference_number AS reference_number,
        r.request_type AS item_type,
        ($request_category_sql) AS item_category,
        r.description AS details,
        r.status AS status,
        r.date_requested AS submitted_at,
        r.admin_response AS admin_note,
        u.id AS user_id,
        u.profile_picture,
        u.fullname,
        u.email,
        NULL AS phone_number,
        COALESCE(s_locks.slot_date, se.event_date) AS event_date,
        COALESCE(s_locks.slot_time, se.event_time) AS event_time,
        COALESCE(doc_counts.document_count, 0) AS document_count,
        COALESCE(pay_counts.payment_count, 0) AS payment_count,
        COALESCE(pay_counts.verified_payment_count, 0) AS verified_payment_count,
        $record_holder_select AS record_holder_name,
        $matched_id_select AS matched_record_id,
        $matched_type_select AS matched_record_type,
        $match_status_select AS match_status,
        $match_details_select AS match_details
    FROM requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN (
        SELECT request_id, COUNT(*) AS document_count
        FROM request_documents
        WHERE document_type = 'requirement' AND deleted_at IS NULL
        GROUP BY request_id
    ) doc_counts ON doc_counts.request_id = r.request_id
    LEFT JOIN (
        SELECT request_id,
               COUNT(*) AS payment_count,
               COUNT(CASE WHEN status = 'verified' THEN 1 END) AS verified_payment_count
        FROM request_payments
        GROUP BY request_id
    ) pay_counts ON pay_counts.request_id = r.request_id
    LEFT JOIN (
        SELECT source_id,
               MIN(slot_date) AS slot_date,
               MIN(slot_time) AS slot_time
        FROM schedule_slot_locks
        WHERE source_type = 'request' AND status = 'active'
        GROUP BY source_id
    ) s_locks ON s_locks.source_id = r.request_id
    LEFT JOIN (
        SELECT source_id,
               MIN(event_date) AS event_date,
               MIN(start_time) AS event_time
        FROM schedule_events
        WHERE source_type = 'request' AND status != 'cancelled'
        GROUP BY source_id
    ) se ON se.source_id = r.request_id
    WHERE $request_where_sql
";

$reservation_select = "
    SELECT
        'reservation' AS item_source,
        r.reservation_id AS item_id,
        CONCAT('RES-', LPAD(r.reservation_id, 6, '0')) AS reference_number,
        r.reservation_type AS item_type,
        ($reservation_category_sql) AS item_category,
        r.event_details AS details,
        r.status AS status,
        r.created_at AS submitted_at,
        r.admin_notes AS admin_note,
        u.id AS user_id,
        u.profile_picture,
        u.fullname,
        u.email,
        u.phone_number,
        r.event_date,
        r.event_time,
        0 AS document_count,
        0 AS payment_count,
        0 AS verified_payment_count,
        NULL AS record_holder_name,
        NULL AS matched_record_id,
        NULL AS matched_record_type,
        NULL AS match_status,
        NULL AS match_details
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE $reservation_where_sql
";

$unified_sql = "$request_select UNION ALL $reservation_select";
$total_result = $conn->query("SELECT COUNT(*) as count FROM ($unified_sql) unified_items");
$total = $total_result ? (int) $total_result->fetch_assoc()['count'] : 0;
if (!$total_result) {
    $error = 'Error loading request count: ' . $conn->error;
}
$pagination = getPaginationData($page, $limit, $total);

// Sort by requested schedule time (earliest to latest) when service date is filtered; otherwise by submission date
$order_by_sql = ($service_date !== '')
    ? "event_date ASC, COALESCE(event_time, '23:59:59') ASC, submitted_at ASC"
    : "submitted_at DESC";

$sql = "SELECT * FROM ($unified_sql) unified_items
        ORDER BY $order_by_sql
        LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);
if (!$result) {
    $error = 'Error loading requests: ' . $conn->error;
}

// Collect rows and calculate same-time schedule overlaps
$requests_list = [];
$overlapping_items = [];
$date_slots = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $requests_list[] = $row;
        if (!empty($row['event_date']) && !empty($row['event_time']) && ($row['item_category'] ?? '') !== 'certificate') {
            $dayKey = (string) $row['event_date'];
            $timeRaw = (string) $row['event_time'];
            $ts = strtotime('2000-01-01 ' . $timeRaw);
            if ($ts !== false) {
                $startMin = (int) date('H', $ts) * 60 + (int) date('i', $ts);
                $endMin = $startMin + 60; // Standard 1-hour booking slot
                $itemKey = $row['item_source'] . '_' . $row['item_id'];
                $date_slots[$dayKey][] = [
                    'key' => $itemKey,
                    'start' => $startMin,
                    'end' => $endMin
                ];
            }
        }
    }
}

foreach ($date_slots as $dayKey => $slots) {
    $n = count($slots);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            // Two 1-hour slots overlap if start1 < end2 AND end1 > start2
            if ($slots[$i]['start'] < $slots[$j]['end'] && $slots[$i]['end'] > $slots[$j]['start']) {
                $overlapping_items[$slots[$i]['key']] = true;
                $overlapping_items[$slots[$j]['key']] = true;
            }
        }
    }
}

// Summary bar text
$formatted_summary_date = '';
if ($service_date === 'this_week') {
    $formatted_summary_date = 'this week (' . date('l, M j', strtotime($week_start_str)) . ' – ' . date('l, M j, Y', strtotime($week_end_str)) . ')';
} elseif ($is_valid_date) {
    $formatted_summary_date = date('l, M j, Y', strtotime($service_date));
}

$page_title = 'Manage Requests';

// Set breadcrumb data
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Requests' => null
];

include '../templates/header.php'; 
?>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php 
    $page_header_title = 'Manage Requests';
    $page_header_subtitle = 'Review and manage parish service requests, requirements, and status updates.';
    $page_header_icon = 'fa-list-check';
    $show_back_button = true;
    $back_button_url = BASE_URL . 'admin/dashboard.php';
    include '../includes/page_header.php'; 
    ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card pds-card pds-request-management">
        <div class="card-body">
            <!-- Filter Tabs -->
            <div class="pds-filter-tabs mb-3" role="group" aria-label="Filter by request status">
                <a href="<?php echo e(adminRequestsUrl(['status' => '', 'page' => '1'])); ?>" 
                   data-status="all" 
                   class="pds-filter-tab <?php echo empty($status_filter) ? 'active' : ''; ?>">
                    All
                </a>
                <a href="<?php echo e(adminRequestsUrl(['status' => 'pending', 'page' => '1'])); ?>" 
                   data-status="pending" 
                   class="pds-filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="<?php echo e(adminRequestsUrl(['status' => 'processing', 'page' => '1'])); ?>" 
                   data-status="processing" 
                   class="pds-filter-tab <?php echo $status_filter === 'processing' ? 'active' : ''; ?>">
                    Processing
                </a>
                <a href="<?php echo e(adminRequestsUrl(['status' => 'completed', 'page' => '1'])); ?>" 
                   data-status="completed" 
                   class="pds-filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                    Completed
                </a>
                <a href="<?php echo e(adminRequestsUrl(['status' => 'rejected', 'page' => '1'])); ?>" 
                   data-status="rejected" 
                   class="pds-filter-tab <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>">
                    Rejected
                </a>
            </div>

            <!-- Filter Controls Form -->
            <form id="requestsFilterForm" class="pds-filter-form mb-3" method="GET" action="manage-requests.php">
                <input type="hidden" name="status" value="<?php echo e($status_filter); ?>">

                <div class="row g-2 align-items-end">
                    <div class="col-xl-3 col-lg-3 col-md-6 col-12">
                        <label for="requestSearch" class="form-label pds-filter-label">Search</label>
                        <input id="requestSearch" 
                               class="form-control pds-form-control pds-filter-input" 
                               type="text" 
                               name="q" 
                               value="<?php echo e($search); ?>" 
                               placeholder="Search reference, parishioner, type, or details">
                    </div>

                    <div class="col-xl-2 col-lg-2 col-md-6 col-12">
                        <label for="requestTypeFilter" class="form-label pds-filter-label">Request Category</label>
                        <select id="requestTypeFilter" class="form-select pds-form-select pds-filter-input" name="type">
                            <option value="">All Categories</option>
                            <?php foreach ($type_groups as $category => $group): ?>
                                <option value="<?php echo e($category); ?>" <?php echo $type_filter === $category ? 'selected' : ''; ?>>
                                    <?php echo e($group['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-3 col-lg-3 col-md-6 col-12">
                        <label for="serviceDateInput" class="form-label pds-filter-label">Service Date</label>
                        <div id="serviceDateWrap" class="pds-service-date-wrap position-relative">
                            <svg class="service-date-calendar-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <input id="serviceDateInput" 
                                   class="form-control pds-form-control pds-service-date-input pds-filter-input" 
                                   type="text" 
                                   name="service_date" 
                                   value="<?php echo e($service_date); ?>" 
                                   placeholder="YYYY-MM-DD" 
                                   autocomplete="off" 
                                   <?php echo $type_filter === 'certificate' ? 'disabled' : ''; ?> 
                                   aria-describedby="serviceDateHelper">
                            <button type="button" 
                                    id="serviceDateClearBtn" 
                                    class="service-date-clear-btn" 
                                    aria-label="Clear date filter" 
                                    style="<?php echo empty($service_date) ? 'display: none;' : ''; ?>">
                                <i class="fas fa-times" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-xl-2 col-lg-2 col-md-6 col-12">
                        <label for="requestStatusFilter" class="form-label pds-filter-label">Status</label>
                        <select id="requestStatusFilter" class="form-select pds-form-select pds-filter-input" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach (['pending', 'processing', 'completed', 'rejected'] as $status_option): ?>
                                <option value="<?php echo e($status_option); ?>" <?php echo $status_filter === $status_option ? 'selected' : ''; ?>>
                                    <?php echo e(ucfirst($status_option)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-2 col-lg-2 col-md-12 col-12">
                        <label class="form-label pds-filter-label d-none d-lg-block invisible" aria-hidden="true">&nbsp;</label>
                        <div class="d-grid d-md-flex gap-2">
                            <button class="btn btn-primary pds-btn pds-btn-primary-gold pds-filter-btn flex-fill" type="submit">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <?php if ($search !== '' || $status_filter !== '' || $type_filter !== '' || $service_date !== ''): ?>
                                <a class="btn btn-outline-secondary pds-btn pds-btn-ghost-outline pds-filter-btn" href="manage-requests.php" title="Clear all filters">
                                    Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Auxiliary Service Date Row (Helper & Quick Chips) -->
                <div class="pds-filter-aux-bar d-flex flex-wrap align-items-center justify-content-between gap-2 pt-2 mt-1">
                    <div id="serviceDateHelper" class="form-text small text-muted my-0">
                        <i class="fas fa-info-circle me-1" aria-hidden="true"></i><?php echo $type_filter === 'certificate' ? 'Not applicable to certificates' : 'Day the parishioner wants the blessing or service.'; ?>
                    </div>
                    <div class="service-date-chips d-flex align-items-center gap-1">
                        <span class="small text-muted me-1 fw-medium">Quick date:</span>
                        <button type="button" 
                                class="service-date-chip <?php echo $service_date === $today_str ? 'active' : ''; ?>" 
                                data-date="<?php echo $today_str; ?>" 
                                aria-label="Filter requests for today">Today</button>
                        <button type="button" 
                                class="service-date-chip <?php echo $service_date === $tomorrow_str ? 'active' : ''; ?>" 
                                data-date="<?php echo $tomorrow_str; ?>" 
                                aria-label="Filter requests for tomorrow">Tomorrow</button>
                        <button type="button" 
                                class="service-date-chip <?php echo $service_date === 'this_week' ? 'active' : ''; ?>" 
                                data-date="this_week" 
                                aria-label="Filter requests for this week">This week</button>
                    </div>
                </div>
            </form>

            <!-- Result Summary Bar when Service Date is Active -->
            <?php if ($service_date !== ''): ?>
                <div class="alert alert-light border d-flex align-items-center justify-content-between pds-service-date-summary mb-3" role="status">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="badge bg-dark text-gold"><i class="fas fa-calendar-day me-1"></i> Service Date</span>
                        <span>
                            <?php if ($total > 0): ?>
                                <strong>Showing <?php echo $total; ?> <?php echo $total === 1 ? 'request' : 'requests'; ?></strong> for <?php echo e($formatted_summary_date); ?>
                            <?php else: ?>
                                <span>No blessing or service requests for this day.</span>
                            <?php endif; ?>
                        </span>
                        <?php if ($show_certificate_note): ?>
                            <span class="text-muted small ps-2 border-start"><i class="fas fa-info-circle me-1"></i>Certificates are not shown because they have no service date.</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="<?php echo e(adminRequestsUrl(['service_date' => '', 'page' => '1'])); ?>" class="btn btn-sm btn-outline-secondary pds-btn-clear-date" aria-label="Clear service date filter">
                            <i class="fas fa-times me-1"></i>Clear date
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Requests Table -->
            <?php if (!empty($requests_list)): ?>
                <div class="table-responsive pds-table-wrap">
                    <table class="table table-hover pds-phase-table align-middle" style="min-width: 980px;">
                        <thead>
                            <tr>
                                <th>Ref #</th>
                                <th>User</th>
                                <th>Type</th>
                                <th>Requested Schedule</th>
                                <th>Requirements</th>
                                <th>Payments</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests_list as $request): ?>
                                <?php
                                    $is_reservation = $request['item_source'] === 'reservation';
                                    $raw_type = strtolower((string)$request['item_type']);
                                    $type_label = match ($raw_type) {
                                        'first_communion_service', 'first_communion', 'communion' => 'First Communion',
                                        'confirmation_service', 'confirmation' => 'Confirmation',
                                        'baptism_service', 'baptism' => 'Baptism',
                                        'marriage_wedding_service', 'marriage', 'wedding' => 'Marriage / Wedding',
                                        'funeral_mass', 'funeral' => 'Funeral Mass',
                                        'anointing_of_the_sick' => 'Anointing of the Sick',
                                        'patronal_fiesta' => 'Patronal Fiesta',
                                        default => ucfirst(str_replace('_', ' ', $request['item_type']))
                                    };
                                    $category_label = adminRequestCategoryLabel($request['item_category']);
                                    $raw_item_type = strtolower((string)($request['item_type'] ?? ''));
                                    $is_comm_or_conf = in_array($raw_item_type, ['first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation'], true);
                                    $has_schedule = !empty($request['event_date']) && ($request['item_category'] ?? '') !== 'certificate' && !$is_comm_or_conf;
                                    $itemKey = $request['item_source'] . '_' . $request['item_id'];
                                    $has_conflict = !empty($overlapping_items[$itemKey]);
                                ?>
                                <tr>
                                    <td><strong><?php echo e($request['reference_number']); ?></strong></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php echo renderUserAvatar($request, 32); ?>
                                            <div>
                                                <div><strong><?php echo sanitize($request['fullname']); ?></strong></div>
                                                <small class="text-muted"><?php echo e($request['email']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo e($type_label); ?><br>
                                        <span class="pds-inline-tag"><?php echo e($category_label); ?></span>
                                        <?php if (($request['item_category'] ?? '') === 'certificate'): ?>
                                            <?php
                                                $mStatus = $request['match_status'] ?? 'unmatched';
                                                $mRecId = intval($request['matched_record_id'] ?? 0);
                                                if ($mStatus === 'matched' && $mRecId > 0):
                                            ?>
                                                <div class="mt-1">
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle d-inline-flex align-items-center gap-1" style="font-size: 11px; padding: 2px 7px;">
                                                        <i class="fas fa-circle-check"></i> Record Found
                                                    </span>
                                                </div>
                                            <?php elseif ($mStatus === 'multiple'): ?>
                                                <div class="mt-1">
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle d-inline-flex align-items-center gap-1" style="font-size: 11px; padding: 2px 7px;" title="Multiple potential matches found - verify record before generating">
                                                        <i class="fas fa-triangle-exclamation"></i> Possible Matches (Verify)
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <div class="mt-1">
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle d-inline-flex align-items-center gap-1" style="font-size: 11px; padding: 2px 7px;" title="No matching sacramental record found automatically">
                                                        <i class="fas fa-magnifying-glass"></i> Needs Manual Review
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Requested Schedule Column -->
                                    <td>
                                        <?php if ($has_schedule): ?>
                                            <div class="fw-semibold text-dark"><?php echo formatDate($request['event_date']); ?></div>
                                            <?php if (!empty($request['event_time'])): ?>
                                                <div class="text-muted small"><?php echo formatScheduleSlotRange($request['event_time']); ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small">Time to be set</div>
                                            <?php endif; ?>
                                            <?php if ($has_conflict): ?>
                                                <span class="badge pds-badge-conflict mt-1" title="Schedule conflict: overlapping time slot on the same day">
                                                    <i class="fas fa-clock me-1"></i>Same time
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (intval($request['document_count'] ?? 0) > 0): ?>
                                            <span class="pds-badge pds-badge-neutral"><i class="fas fa-paperclip"></i> <?php echo intval($request['document_count']); ?> <?php echo intval($request['document_count']) === 1 ? 'file' : 'files'; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (intval($request['payment_count'] ?? 0) > 0): ?>
                                            <span class="pds-badge pds-badge-approved"><i class="fas fa-receipt"></i> <?php echo intval($request['verified_payment_count'] ?? 0); ?>/<?php echo intval($request['payment_count']); ?> verified</span>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $disp_status = strtolower($request['status']) === 'submitted' ? 'pending' : $request['status'];
                                        ?>
                                        <span class="<?php echo e(pdsStatusClass($disp_status)); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $disp_status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatDate($request['submitted_at']); ?></td>
                                    <td>
                                        <div class="pds-action-btn-group">
                                            <?php if (!$is_reservation): ?>
                                                <?php if (($request['item_category'] ?? '') === 'certificate' && ($request['match_status'] ?? '') === 'matched' && !empty($request['matched_record_id'])): ?>
                                                    <a href="certificate-generator.php?request_id=<?php echo intval($request['item_id']); ?>&cert_type=<?php echo urlencode($request['matched_record_type'] ?: 'baptism'); ?>&record_id=<?php echo intval($request['matched_record_id']); ?>" class="btn btn-sm pds-row-action text-success border-success" title="Fast-Track: Open Generator with pre-matched record">
                                                        <i class="fas fa-file-signature"></i> <span>Gen Cert</span>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="request-workflow.php?id=<?php echo intval($request['item_id']); ?>" class="btn btn-sm pds-row-action">
                                                    <i class="fas fa-eye"></i> <span>View</span>
                                                </a>
                                                <form method="POST" action="" class="pds-action-form" onsubmit="return confirm('Archive this request? It will be hidden from this list but kept in the database.');">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="action" value="archive_request">
                                                    <input type="hidden" name="request_id" value="<?php echo intval($request['item_id']); ?>">
                                                    <button type="submit" class="btn btn-sm pds-row-action pds-row-action-archive">
                                                        <i class="fas fa-box-archive"></i> <span>Archive</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <a href="manage-reservations.php?q=<?php echo urlencode($request['email']); ?>" class="btn btn-sm pds-row-action">
                                                    <i class="fas fa-calendar-check"></i> <span>Manage</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <nav class="mt-3" aria-label="Requests pagination">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo e(adminRequestsUrl(['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <?php if ($service_date !== ''): ?>
                        No blessing or service requests for this day. 
                        <a href="<?php echo e(adminRequestsUrl(['service_date' => '', 'page' => '1'])); ?>" class="alert-link ms-2">Clear date</a>
                    <?php else: ?>
                        No requests found.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="../assets/js/admin-service-date-filter.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/admin-service-date-filter.js') ? filemtime(__DIR__ . '/../assets/js/admin-service-date-filter.js') : time(); ?>"></script>

<?php include '../templates/footer.php'; ?>
