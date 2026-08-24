<?php
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/ReservationService.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Make Reservation' => null
];

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$my_reservations = [];
$resources = [];
$resourceResult = $conn->query("SELECT resource_id,resource_type,name,location,capacity FROM resources WHERE status='available' AND deleted_at IS NULL ORDER BY resource_type,name");
if ($resourceResult) $resources = $resourceResult->fetch_all(MYSQLI_ASSOC);
$idempotency_key = generateCsrfToken();
$idempotency_key = hash('sha256', 'reservation|' . $user_id . '|' . $idempotency_key);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
    requireValidCsrfToken();
    try {
        $service = new ReservationService($conn);
        $service->create($user_id, [
            'reservation_type'=>$_POST['reservation_type']??'',
            'start_at'=>(($_POST['event_date']??'').' '.($_POST['event_time']??'').':00'),
            'service_duration_minutes'=>$_POST['service_duration_minutes']??60,
            'setup_duration_minutes'=>$_POST['setup_duration_minutes']??0,
            'cleanup_duration_minutes'=>$_POST['cleanup_duration_minutes']??0,
            'event_details'=>$_POST['event_details']??'',
            'resource_ids'=>$_POST['resource_ids']??[],
        ], (string)($_POST['idempotency_key']??''));
        $success = 'Reservation request submitted successfully!';
        $idempotency_key = hash('sha256', random_bytes(32));
    } catch (Throwable $e) { $error = $e->getMessage(); if($e instanceof DomainException&&!empty($_POST['event_date'])&&!empty($_POST['event_time'])&&!empty($_POST['resource_ids'])){try{$suggestions=(new ResourceAvailabilityService($conn))->suggestAvailableSlots((array)$_POST['resource_ids'],$_POST['event_date'].' '.$_POST['event_time'].':00',(int)($_POST['service_duration_minutes']??60),(int)($_POST['setup_duration_minutes']??0),(int)($_POST['cleanup_duration_minutes']??0));if($suggestions)$error.=' Available alternatives: '.implode(', ',array_map(fn($slot)=>date('M j, Y g:i A',strtotime($slot['start_at'])),$suggestions)).'.';}catch(Throwable $ignored){}} }
}

$stmt = $conn->prepare("SELECT r.reservation_id,r.reservation_type,r.event_date,r.event_time,r.start_at,r.end_at,r.event_details,r.status,r.admin_notes,r.created_at,GROUP_CONCAT(x.name ORDER BY x.name SEPARATOR ', ') AS resource_names FROM reservations r LEFT JOIN reservation_resources rr ON rr.reservation_id=r.reservation_id LEFT JOIN resources x ON x.resource_id=rr.resource_id WHERE r.user_id=? GROUP BY r.reservation_id ORDER BY r.start_at DESC LIMIT 10");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $my_reservations[] = $row;
    }
    $stmt->close();
}

$page_title = 'Make Reservation';
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/breadcrumb.php'; ?>
<?php include '../includes/back_button.php'; ?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Make a Reservation</h5>
                </div>
                <div class="card-body">
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <?php echo $success; ?>
                            <a href="dashboard.php" class="alert-link">Return to Dashboard</a>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="idempotency_key" value="<?php echo e($idempotency_key); ?>">
                        <div class="mb-3">
                            <label for="reservation_type" class="form-label">Reservation Type</label>
                            <select class="form-select form-select-lg" id="reservation_type" name="reservation_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="wedding">Wedding</option>
                                <option value="baptism">Baptism</option>
                                <option value="confirmation">Confirmation</option>
                                <option value="burial">Burial/Funeral</option>
                                <option value="church_venue">Church Venue Reservation</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="resource_ids" class="form-label">Parish Resource</label>
                            <select class="form-select" id="resource_ids" name="resource_ids[]" multiple required size="<?php echo min(5, max(2, count($resources))); ?>">
                                <?php foreach ($resources as $resource): ?>
                                    <option value="<?php echo intval($resource['resource_id']); ?>"><?php echo e($resource['name'] . ' (' . ucfirst($resource['resource_type']) . ')' . ($resource['capacity'] ? ' — capacity ' . $resource['capacity'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hold Ctrl (Windows) or Command (Mac) to select multiple resources.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="event_date" class="form-label">Event Date</label>
                                <input type="date" class="form-control" id="event_date" name="event_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="event_time" class="form-label">Event Time</label>
                                <input type="time" class="form-control" id="event_time" name="event_time" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="form-label" for="service_duration_minutes">Service (minutes)</label><input class="form-control" type="number" id="service_duration_minutes" name="service_duration_minutes" min="15" max="1440" value="60" required></div>
                            <div class="col-md-4 mb-3"><label class="form-label" for="setup_duration_minutes">Setup (minutes)</label><input class="form-control" type="number" id="setup_duration_minutes" name="setup_duration_minutes" min="0" max="1440" value="0" required></div>
                            <div class="col-md-4 mb-3"><label class="form-label" for="cleanup_duration_minutes">Cleanup (minutes)</label><input class="form-control" type="number" id="cleanup_duration_minutes" name="cleanup_duration_minutes" min="0" max="1440" value="0" required></div>
                        </div>

                        <div class="mb-3">
                            <label for="event_details" class="form-label">Additional Details</label>
                            <textarea class="form-control" id="event_details" name="event_details" rows="4" placeholder="Provide any additional information about your reservation..."></textarea>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> Your reservation will be reviewed by church staff and you will be notified of approval status.
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Submit Reservation</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clock-rotate-left"></i> My Recent Reservations</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($my_reservations)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Type</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Resources</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_reservations as $reservation): ?>
                                        <tr>
                                            <td><?php echo e(ucfirst(str_replace('_', ' ', $reservation['reservation_type']))); ?></td>
                                            <td><?php echo formatDate($reservation['event_date']); ?></td>
                                            <td><?php echo e(substr((string) $reservation['event_time'], 0, 5)); ?></td>
                                            <td><?php echo e($reservation['resource_names'] ?: 'Unassigned'); ?></td>
                                            <td><span class="badge bg-<?php echo getStatusBadgeClass($reservation['status']); ?>"><?php echo e(ucfirst($reservation['status'])); ?></span></td>
                                            <td><?php echo e($reservation['admin_notes'] ?: 'No notes yet'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No reservation requests yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
