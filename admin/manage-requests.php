<?php
/**
 * Manage Requests Page
 * Admin interface for managing user requests
 */

// Include centralized session management
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

// Require admin access
requireAdmin();
if (!hasAnyPermission(['requests.manage', 'reservations.manage'])) {
    redirect('dashboard.php');
}
ensureEmailNotificationSchema($conn);
ensureRequestDocumentsSchema($conn);
ensureRequestPaymentsSchema($conn);

$error = '';
$success = '';

// Ensure Request Archive Column Function - Documents this helper's role in the parish management workflow.
function ensureRequestArchiveColumn($conn) {
    $result = $conn->query("SHOW COLUMNS FROM requests LIKE 'deleted_at'");
    if ($result && $result->num_rows > 0) {
        return true;
    }

    return $conn->query("ALTER TABLE requests ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
}

// Request Category Helpers - Keep admin filtering readable while preserving existing request values.
function adminRequestTypeGroups() {
    return [
        'certificate' => [
            'label' => 'Certificate',
            'types' => ['baptismal_certificate', 'confirmation_certificate', 'first_communion_certificate']
        ],
        'blessing' => [
            'label' => 'Blessing',
            'types' => ['house_blessing', 'car_blessing', 'vehicle_blessing', 'business_blessing', 'office_blessing', 'event_blessing']
        ],
        'sacramental' => [
            'label' => 'Sacramental Services',
            'types' => [
                'baptism_service',
                'marriage_wedding_service',
                'funeral_mass',
                'anointing_of_the_sick',
                'patronal_fiesta',
                'church_reservation',
                'wedding_reservation',
                'burial_reservation',
                'wedding',
                'baptism',
                'confirmation',
                'burial',
                'church_venue'
            ]
        ],
    ];
}

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

function adminRequestCategoryLabel($category) {
    $groups = adminRequestTypeGroups();
    return $groups[$category]['label'] ?? 'Other';
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
        $conflict = ['conflict' => false, 'message' => ''];
        if ($status === 'approved') {
            $conflict = reservationApprovalConflict($conn, $reservation_id);
        }

        if ($conflict['conflict']) {
            $error = $conflict['message'] . ' Please choose another schedule before approving.';
        } else {
            $stmt = $conn->prepare("UPDATE reservations SET status = ?, admin_notes = ? WHERE reservation_id = ?");
            if (!$stmt) {
                $error = 'Unable to prepare reservation update.';
            } else {
                $stmt->bind_param('ssi', $status, $admin_notes, $reservation_id);
                if ($stmt->execute()) {
                    $lookup = $conn->prepare("SELECT r.user_id, r.reservation_type, u.phone_number FROM reservations r JOIN users u ON u.id = r.user_id WHERE r.reservation_id = ?");
                    if ($lookup) {
                        $lookup->bind_param('i', $reservation_id);
                        $lookup->execute();
                        $reservation = $lookup->get_result()->fetch_assoc();
                        $lookup->close();

                        if ($reservation) {
                            createNotification(
                                $conn,
                                intval($reservation['user_id']),
                                'Reservation Update',
                                'Your ' . ucfirst(str_replace('_', ' ', $reservation['reservation_type'])) . ' reservation is now ' . ucfirst($status) . '.'
                            );
                        }
                    }

                    createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_RESERVATION', 'reservations', $reservation_id);

                    if ($status === 'approved') {
                        $sync_result = syncApprovedReservationToCalendar($conn, $reservation_id, $_SESSION['user_id']);
                        $success = 'Reservation updated successfully.';
                        $success .= $sync_result['success']
                            ? ' It is now synced to the calendar.'
                            : ' Calendar sync failed: ' . $sync_result['message'];
                    } else {
                        ensureScheduleEventsTable($conn);
                        $cancel_stmt = $conn->prepare("UPDATE schedule_events SET status = 'cancelled' WHERE source_type = 'reservation' AND source_id = ?");
                        if ($cancel_stmt) {
                            $cancel_stmt->bind_param('i', $reservation_id);
                            $cancel_stmt->execute();
                            $cancel_stmt->close();
                        }
                        $success = 'Reservation updated successfully.';
                    }
                } else {
                    $error = 'Error updating reservation: ' . $conn->error;
                }
                $stmt->close();
            }
        }
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
    $status = $conn->real_escape_string($_POST['status']);
    $admin_response = $conn->real_escape_string($_POST['admin_response']);

    $approval_conflict = ['conflict' => false, 'message' => ''];
    if ($status === 'approved') {
        $approval_conflict = requestApprovalConflict($conn, $request_id);
    }

    if ($approval_conflict['conflict']) {
        $error = $approval_conflict['message'] . ' Please choose another schedule before approving.';
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
        if ($status === 'approved') {
            $sync_result = syncApprovedRequestToCalendar($conn, $request_id, $_SESSION['user_id']);
            $success = 'Request updated successfully!';
            if ($sync_result['success'] && in_array($sync_result['message'], ['Calendar event created.', 'Calendar event updated.'], true)) {
                $success .= ' It is now synced to the calendar.';
            } elseif (!$sync_result['success']) {
                $success .= ' Calendar sync skipped: ' . $sync_result['message'];
            }
        } else {
            if (in_array($status, ['pending', 'processing', 'rejected'], true)) {
                cancelLinkedRequestCalendarEvent($conn, $request_id);
            }
            $success = 'Request updated successfully!';
        }
        } else {
            $error = 'Error updating request: ' . $conn->error;
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');
$type_groups = adminRequestTypeGroups();
$type_filter = isset($type_groups[$type_filter]) ? $type_filter : '';
$request_category_sql = adminRequestCategorySql('r.request_type');
$reservation_category_sql = adminRequestCategorySql('r.reservation_type');
$request_where = ["r.deleted_at IS NULL"];
$reservation_where = ["1=1"];
if (!empty($status_filter)) {
    $status_filter = $conn->real_escape_string($status_filter);
    $request_where[] = "r.status = '$status_filter'";
    $reservation_where[] = "r.status = '$status_filter'";
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
$request_where_sql = implode(' AND ', $request_where);
$reservation_where_sql = implode(' AND ', $reservation_where);

$page = intval($_GET['page'] ?? 1);
$limit = 10;

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
        u.fullname,
        u.email,
        NULL AS phone_number,
        NULL AS event_date,
        NULL AS event_time,
        COUNT(DISTINCT d.document_id) AS document_count,
        COUNT(DISTINCT p.payment_id) AS payment_count,
        COUNT(DISTINCT CASE WHEN p.status = 'verified' THEN p.payment_id END) AS verified_payment_count
    FROM requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN request_documents d ON d.request_id = r.request_id AND d.document_type = 'requirement' AND d.deleted_at IS NULL
    LEFT JOIN request_payments p ON p.request_id = r.request_id
    WHERE $request_where_sql
    GROUP BY r.request_id
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
        u.fullname,
        u.email,
        u.phone_number,
        r.event_date,
        r.event_time,
        0 AS document_count,
        0 AS payment_count,
        0 AS verified_payment_count
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

$sql = "SELECT * FROM ($unified_sql) unified_items
        ORDER BY submitted_at DESC
        LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);
if (!$result) {
    $error = 'Error loading requests: ' . $conn->error;
}

$page_title = 'Manage Requests';

// Set breadcrumb data
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Requests' => null
];
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <!-- Breadcrumb Navigation -->
    <?php include '../includes/breadcrumb.php'; ?>
    
    <!-- Back Button -->
    <?php include '../includes/back_button.php'; ?>

    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="mb-2"><i class="fas fa-tasks"></i> Manage Requests</h1>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card pds-card pds-request-management">
        <div class="card-body">
            <!-- Filter Buttons -->
            <div class="pds-filter-tabs mb-3" role="group" aria-label="Filter by request status">
                <a href="?status=&type=<?php echo urlencode($type_filter); ?>&q=<?php echo urlencode($search); ?>" data-status="all" class="pds-filter-tab <?php echo empty($status_filter) ? 'active' : ''; ?>">
                    All
                </a>
                <a href="?status=pending&type=<?php echo urlencode($type_filter); ?>&q=<?php echo urlencode($search); ?>" data-status="pending" class="pds-filter-tab <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="?status=approved&type=<?php echo urlencode($type_filter); ?>&q=<?php echo urlencode($search); ?>" data-status="approved" class="pds-filter-tab <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">
                    Approved
                </a>
                <a href="?status=rejected&type=<?php echo urlencode($type_filter); ?>&q=<?php echo urlencode($search); ?>" data-status="rejected" class="pds-filter-tab <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">
                    Rejected
                </a>
                <a href="?status=completed&type=<?php echo urlencode($type_filter); ?>&q=<?php echo urlencode($search); ?>" data-status="completed" class="pds-filter-tab <?php echo $status_filter == 'completed' ? 'active' : ''; ?>">
                    Completed
                </a>
                <a href="?status=cancelled&type=<?php echo urlencode($type_filter); ?>&q=<?php echo urlencode($search); ?>" data-status="cancelled" class="pds-filter-tab <?php echo $status_filter == 'cancelled' ? 'active' : ''; ?>">
                    Cancelled
                </a>
            </div>

            <form class="row g-2 align-items-end mb-3" method="GET" action="">
                <div class="col-md-5">
                    <label for="requestSearch" class="form-label">Search</label>
                    <input id="requestSearch" class="form-control" type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search reference, parishioner, type, or details">
                </div>
                <div class="col-md-3">
                    <label for="requestTypeFilter" class="form-label">Request Category</label>
                    <select id="requestTypeFilter" class="form-select" name="type">
                        <option value="">All Categories</option>
                        <?php foreach ($type_groups as $category => $group): ?>
                            <option value="<?php echo e($category); ?>" <?php echo $type_filter === $category ? 'selected' : ''; ?>>
                                <?php echo e($group['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="requestStatusFilter" class="form-label">Status</label>
                    <select id="requestStatusFilter" class="form-select" name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending', 'approved', 'rejected', 'processing', 'completed', 'cancelled'] as $status_option): ?>
                            <option value="<?php echo e($status_option); ?>" <?php echo $status_filter === $status_option ? 'selected' : ''; ?>>
                                <?php echo e(ucfirst($status_option)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid d-md-flex gap-2">
                    <button class="btn btn-primary pds-btn pds-btn-primary-gold" type="submit"><i class="fas fa-filter"></i> Apply</button>
                    <?php if ($search !== '' || $status_filter !== '' || $type_filter !== ''): ?>
                        <a class="btn btn-outline-secondary pds-btn pds-btn-ghost-outline" href="manage-requests.php">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Requests Table -->
            <?php if ($result && $result->num_rows > 0): ?>
                <div class="table-responsive pds-table-wrap">
                    <table class="table table-hover pds-phase-table">
                        <thead>
                            <tr>
                                <th>Ref #</th>
                                <th>User</th>
                                <th>Type</th>
                                <th>Requirements</th>
                                <th>Payments</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($request = $result->fetch_assoc()): ?>
                                <?php
                                    $is_reservation = $request['item_source'] === 'reservation';
                                    $type_label = ucfirst(str_replace('_', ' ', $request['item_type']));
                                    $category_label = adminRequestCategoryLabel($request['item_category']);
                                    $details = (string) ($request['details'] ?? '');
                                    if ($is_reservation && !empty($request['event_date'])) {
                                        $details = trim(
                                            'Schedule: ' . formatDate($request['event_date']) . ' ' . ($request['event_time'] ? formatTime($request['event_time']) : '') .
                                            "\n" . $details
                                        );
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo $request['reference_number']; ?></strong></td>
                                    <td><?php echo sanitize($request['fullname']); ?><br><small><?php echo $request['email']; ?></small></td>
                                    <td>
                                        <?php echo e($type_label); ?><br>
                                        <span class="pds-inline-tag"><?php echo e($category_label); ?></span>
                                    </td>
                                    <td>
                                        <?php if (intval($request['document_count'] ?? 0) > 0): ?>
                                            <span class="pds-badge pds-badge-neutral"><i class="fas fa-paperclip"></i> <?php echo intval($request['document_count']); ?> file</span>
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
                                        <span class="<?php echo e(pdsStatusClass($request['status'])); ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatDate($request['submitted_at']); ?></td>
                                    <td>
                                        <?php if (!$is_reservation): ?>
                                            <a href="request-workflow.php?id=<?php echo intval($request['item_id']); ?>" class="btn btn-sm pds-row-action">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Archive this request? It will be hidden from this list but kept in the database.');">
                                                <input type="hidden" name="action" value="archive_request">
                                                <input type="hidden" name="request_id" value="<?php echo intval($request['item_id']); ?>">
                                                <button type="submit" class="btn btn-sm pds-row-action">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>&q=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">No requests found</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
