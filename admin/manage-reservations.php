<?php
/**
 * Reservation Management Module - Reviews and updates parish event and facility reservations.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('reservations.manage');
redirect('manage-requests.php?type=sacramental');

$error = '';
$success = '';
$allowed_statuses = ['pending', 'approved', 'rejected', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_reservation') {
    $reservation_id = intval($_POST['reservation_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if ($reservation_id <= 0 || !in_array($status, $allowed_statuses, true)) {
        $error = 'Please choose a valid reservation status.';
    } elseif ($status === 'approved' && reservationApprovalConflict($conn, $reservation_id)['conflict']) {
        $conflict = reservationApprovalConflict($conn, $reservation_id);
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

$status_filter = trim($_GET['status'] ?? '');
$status_filter = in_array($status_filter, $allowed_statuses, true) ? $status_filter : '';
$type_filter = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');
$search_like = '%' . $search . '%';
$page = intval($_GET['page'] ?? 1);
$limit = 10;

$where = ['1=1'];
$types = '';
$params = [];

if ($status_filter !== '') {
    $where[] = 'r.status = ?';
    $types .= 's';
    $params[] = $status_filter;
}

if ($type_filter !== '') {
    $where[] = 'r.reservation_type = ?';
    $types .= 's';
    $params[] = $type_filter;
}

if ($search !== '') {
    $where[] = '(u.fullname LIKE ? OR u.email LIKE ? OR r.reservation_type LIKE ? OR r.event_details LIKE ? OR r.admin_notes LIKE ?)';
    $types .= 'sssss';
    array_push($params, $search_like, $search_like, $search_like, $search_like, $search_like);
}

$where_sql = implode(' AND ', $where);
$total = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS count FROM reservations r JOIN users u ON r.user_id = u.id WHERE $where_sql");
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = intval(($stmt->get_result()->fetch_assoc())['count'] ?? 0);
    $stmt->close();
}

$pagination = getPaginationData($page, $limit, $total);
$reservations = [];
$list_types = $types . 'ii';
$list_params = array_merge($params, [$pagination['offset'], $pagination['limit']]);
$stmt = $conn->prepare("
    SELECT r.*, u.fullname, u.email, u.phone_number
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE $where_sql
    ORDER BY r.created_at DESC, r.event_date DESC, r.event_time DESC
    LIMIT ?, ?
");
if ($stmt) {
    $stmt->bind_param($list_types, ...$list_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }
    $stmt->close();
}

$status_counts = array_fill_keys($allowed_statuses, 0);
$result = $conn->query("SELECT status, COUNT(*) AS count FROM reservations GROUP BY status");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (isset($status_counts[$row['status']])) {
            $status_counts[$row['status']] = intval($row['count']);
        }
    }
}

$types_list = [];
$result = $conn->query("SELECT DISTINCT reservation_type FROM reservations ORDER BY reservation_type");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $types_list[] = $row['reservation_type'];
    }
}

// Reservation Label Function - Documents this helper's role in the parish management workflow.
function reservationLabel($value) {
    return ucfirst(str_replace('_', ' ', (string) $value));
}

$page_title = 'Manage Reservations';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Reservations' => null
];
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <?php include '../includes/breadcrumb.php'; ?>
    <?php include '../includes/back_button.php'; ?>

    <section class="premium-admin-hero">
        <div>
            <span class="premium-pill"><i class="fas fa-calendar-check"></i> Reservation desk</span>
            <h1>Manage Reservations</h1>
            <p>Review parish reservations, update approval status, leave admin notes, and notify parishioners from one focused workspace.</p>
        </div>
        <div class="hero-orb" aria-hidden="true">
            <i class="fas fa-calendar-check"></i>
        </div>
    </section>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="premium-kpi-grid">
        <?php foreach ($status_counts as $status_name => $count): ?>
            <a href="?status=<?php echo urlencode($status_name); ?>" class="premium-kpi-card premium-glass">
                <div class="premium-kpi-icon"><i class="fas fa-circle-check"></i></div>
                <div class="premium-kpi-label"><?php echo e(reservationLabel($status_name)); ?></div>
                <div class="premium-kpi-value"><?php echo intval($count); ?></div>
                <div class="premium-kpi-note"><i class="fas fa-filter"></i> View status</div>
            </a>
        <?php endforeach; ?>
    </section>

    <form class="premium-panel premium-glass mb-3" method="GET" action="">
        <div class="row g-2 align-items-end">
            <div class="col-lg-5">
                <label class="form-label" for="reservationSearch">Search</label>
                <input id="reservationSearch" class="form-control" type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search parishioner, email, type, details, notes" autocomplete="off" data-suggestion-scope="reservations">
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="reservationStatus">Status</label>
                <select id="reservationStatus" class="form-select" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($allowed_statuses as $status_option): ?>
                        <option value="<?php echo e($status_option); ?>" <?php echo $status_filter === $status_option ? 'selected' : ''; ?>>
                            <?php echo e(reservationLabel($status_option)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label" for="reservationType">Type</label>
                <select id="reservationType" class="form-select" name="type">
                    <option value="">All Types</option>
                    <?php foreach ($types_list as $type_option): ?>
                        <option value="<?php echo e($type_option); ?>" <?php echo $type_filter === $type_option ? 'selected' : ''; ?>>
                            <?php echo e(reservationLabel($type_option)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-grid d-md-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
                <?php if ($search !== '' || $status_filter !== '' || $type_filter !== ''): ?>
                    <a class="btn btn-outline-secondary" href="manage-reservations.php">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <section class="premium-panel premium-glass">
        <div class="premium-panel-header">
            <h2 class="premium-panel-title"><i class="fas fa-list-check"></i> Reservation Requests</h2>
            <span class="text-muted"><?php echo intval($total); ?> total</span>
        </div>

        <?php if (!empty($reservations)): ?>
            <div class="premium-table-wrap">
                <table class="premium-admin-table">
                    <thead>
                        <tr>
                            <th>Parishioner</th>
                            <th>Type</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Details</th>
                            <th>Admin Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($reservation['fullname']); ?></strong><br>
                                    <small class="text-muted"><?php echo e($reservation['email']); ?></small>
                                </td>
                                <td><?php echo e(reservationLabel($reservation['reservation_type'])); ?></td>
                                <td><?php echo formatDate($reservation['event_date']); ?><br><small><?php echo e(formatTime($reservation['event_time'])); ?></small></td>
                                <td><span class="premium-status <?php echo e($reservation['status']); ?>"><?php echo e(reservationLabel($reservation['status'])); ?></span></td>
                                <td><?php echo e($reservation['event_details'] ?: 'No details provided'); ?></td>
                                <td><?php echo e($reservation['admin_notes'] ?: 'No notes yet'); ?></td>
                                <td>
                                    <button class="premium-btn secondary" style="min-height:34px; padding:6px 12px; font-size:.82rem;" data-bs-toggle="modal"
                                            data-bs-target="#reservationModal"
                                            data-reservation-id="<?php echo intval($reservation['reservation_id']); ?>"
                                            data-reservation-status="<?php echo e($reservation['status']); ?>"
                                            data-reservation-notes="<?php echo e($reservation['admin_notes']); ?>">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pagination['total_pages'] > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                            <li class="page-item <?php echo $i === $pagination['page'] ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info mb-0">No reservations found.</div>
        <?php endif; ?>
    </section>
</div>

<div class="modal fade" id="reservationModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-check"></i> Update Reservation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update_reservation">
                <input type="hidden" name="reservation_id" id="modalReservationId">
                <div class="mb-3">
                    <label class="form-label" for="modalReservationStatus">Status</label>
                    <select class="form-select" id="modalReservationStatus" name="status" required>
                        <?php foreach ($allowed_statuses as $status_option): ?>
                            <option value="<?php echo e($status_option); ?>"><?php echo e(reservationLabel($status_option)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="modalReservationNotes">Admin Notes</label>
                    <textarea class="form-control" id="modalReservationNotes" name="admin_notes" rows="4" placeholder="Optional note for the parishioner"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Update</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('reservationModal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        document.getElementById('modalReservationId').value = button.getAttribute('data-reservation-id') || '';
        document.getElementById('modalReservationStatus').value = button.getAttribute('data-reservation-status') || 'pending';
        document.getElementById('modalReservationNotes').value = button.getAttribute('data-reservation-notes') || '';
    });
});
</script>

<?php include '../templates/footer.php'; ?>
