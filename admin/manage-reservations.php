<?php
/**
 * Reservation Management Module - Reviews and updates parish event and facility reservations.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/ReservationService.php';

requireAdmin();
requirePermission('reservations.manage');

$error = '';
$success = '';
$allowed_statuses = ['pending', 'approved', 'rejected', 'cancelled'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requireValidCsrfToken();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_reservation') {
    $reservation_id = intval($_POST['reservation_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if ($reservation_id <= 0 || !in_array($status, $allowed_statuses, true)) {
        $error = 'Please choose a valid reservation status.';
    } else {
        try {
            $result = (new ReservationService($conn))->changeStatus($reservation_id, $status, (int)$_SESSION['user_id'], $admin_notes);
            $success = 'Reservation updated successfully. ' . $result['calendar']['message'];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'propose_schedule') {
    try {
        $start = str_replace('T', ' ', trim((string)($_POST['proposed_start_at'] ?? ''))) . ':00';
        $duration = (int)($_POST['proposal_duration_minutes'] ?? 60);
        $expires = trim((string)($_POST['expires_at'] ?? ''));
        $expires = $expires === '' ? null : str_replace('T', ' ', $expires) . ':00';
        (new ReservationService($conn))->proposeSchedule(
            (int)($_POST['reservation_id'] ?? 0),
            [
                'start_at' => $start,
                'service_duration_minutes' => $duration,
                'setup_duration_minutes' => (int)($_POST['setup_duration_minutes'] ?? 0),
                'cleanup_duration_minutes' => (int)($_POST['cleanup_duration_minutes'] ?? 0),
                'resource_ids' => $_POST['resource_ids'] ?? []
            ],
            (int)$_SESSION['user_id'],
            trim((string)($_POST['proposal_reason'] ?? '')),
            $expires
        );
        $success = 'Schedule proposal sent to the parishioner.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($e instanceof DomainException && isset($start)) {
            $suggestions = (new ResourceAvailabilityService($conn))->suggestAvailableSlots((array)($_POST['resource_ids'] ?? []), $start, $duration, (int)($_POST['setup_duration_minutes'] ?? 0), (int)($_POST['cleanup_duration_minutes'] ?? 0));
            if ($suggestions) {
                $error .= ' Available alternatives: ' . implode(', ', array_map(fn($slot) => date('M j, Y g:i A', strtotime($slot['start_at'])), $suggestions)) . '.';
            }
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

$available_resources = [];
$resource_result = $conn->query("SELECT resource_id, name, resource_type FROM resources WHERE status = 'available' AND deleted_at IS NULL ORDER BY resource_type, name");
if ($resource_result) {
    $available_resources = $resource_result->fetch_all(MYSQLI_ASSOC);
}

function reservationLabel($value) {
    return ucfirst(str_replace('_', ' ', (string) $value));
}

$page_title = 'Manage Reservations';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Reservations' => null
];

include '../templates/header.php';
?>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Manage Reservations';
    $page_header_subtitle = 'Review parish reservations, update approval status, and manage parish resources.';
    $page_header_icon = 'fa-calendar-check';
    $show_back_button = true;
    $back_button_url = BASE_URL . 'admin/dashboard.php';
    include '../includes/page_header.php';
    ?>

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
                                    <button class="premium-btn secondary" style="min-height:34px; padding:6px 12px; font-size:.82rem;" data-bs-toggle="modal" data-bs-target="#proposalModal" data-reservation-id="<?php echo intval($reservation['reservation_id']); ?>"><i class="fas fa-clock"></i> Propose time</button>
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
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Reservation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="update_reservation">
                <input type="hidden" name="reservation_id" id="modal_reservation_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="modal_status">Status</label>
                        <select class="form-select" id="modal_status" name="status" required>
                            <?php foreach ($allowed_statuses as $status_option): ?>
                                <option value="<?php echo e($status_option); ?>"><?php echo e(reservationLabel($status_option)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="modal_admin_notes">Admin Notes</label>
                        <textarea class="form-control" id="modal_admin_notes" name="admin_notes" rows="4" placeholder="Add administrative notes, requirements, or instructions for the parishioner..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="proposalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Propose Alternative Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="propose_schedule">
                <input type="hidden" name="reservation_id" id="modal_proposal_reservation_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="proposed_start_at">Proposed Date &amp; Start Time</label>
                        <input class="form-control" type="datetime-local" id="proposed_start_at" name="proposed_start_at" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-sm-4">
                            <label class="form-label" for="proposal_duration_minutes">Service (mins)</label>
                            <input class="form-control" type="number" id="proposal_duration_minutes" name="proposal_duration_minutes" value="60" min="15" step="15" required>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="setup_duration_minutes">Setup (mins)</label>
                            <input class="form-control" type="number" id="setup_duration_minutes" name="setup_duration_minutes" value="0" min="0" step="15">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="cleanup_duration_minutes">Cleanup (mins)</label>
                            <input class="form-control" type="number" id="cleanup_duration_minutes" name="cleanup_duration_minutes" value="0" min="0" step="15">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="proposal_reason">Reason for Proposal</label>
                        <textarea class="form-control" id="proposal_reason" name="proposal_reason" rows="3" placeholder="Explain why the original slot was unavailable..." required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="expires_at">Proposal Expires At (Optional)</label>
                        <input class="form-control" type="datetime-local" id="expires_at" name="expires_at">
                    </div>
                    <?php if (!empty($available_resources)): ?>
                        <div class="mb-3">
                            <label class="form-label">Attach Resources / Facilities</label>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($available_resources as $resource): ?>
                                    <label class="badge bg-light text-dark border p-2 d-inline-flex align-items-center gap-2">
                                        <input type="checkbox" name="resource_ids[]" value="<?php echo intval($resource['resource_id']); ?>">
                                        <span><?php echo e($resource['name']); ?> <small class="text-muted">(<?php echo e($resource['resource_type']); ?>)</small></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Proposal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var reservationModal = document.getElementById('reservationModal');
    if (reservationModal) {
        reservationModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            var id = button.getAttribute('data-reservation-id');
            var status = button.getAttribute('data-reservation-status');
            var notes = button.getAttribute('data-reservation-notes');
            document.getElementById('modal_reservation_id').value = id || '';
            document.getElementById('modal_status').value = status || 'pending';
            document.getElementById('modal_admin_notes').value = notes || '';
        });
    }
    var proposalModal = document.getElementById('proposalModal');
    if (proposalModal) {
        proposalModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            var id = button.getAttribute('data-reservation-id');
            document.getElementById('modal_proposal_reservation_id').value = id || '';
        });
    }
});
</script>

<?php include '../templates/footer.php'; ?>
