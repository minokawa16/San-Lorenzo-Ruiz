<?php
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
    $allowed_types = ['wedding', 'baptism', 'confirmation', 'burial', 'church_venue'];
    $reservation_type = $_POST['reservation_type'] ?? '';
    $event_date = $_POST['event_date'] ?? '';
    $event_time = $_POST['event_time'] ?? '';
    $event_details = trim($_POST['event_details'] ?? '');
    
    if (!in_array($reservation_type, $allowed_types, true)) {
        $error = 'Please select a valid reservation type.';
    } elseif ($event_date === '' || $event_time === '') {
        $error = 'Please choose an event date and time.';
    } elseif (strtotime($event_date) < strtotime(date('Y-m-d'))) {
        $error = 'Please choose today or a future date.';
    } else {
        $stmt = $conn->prepare("SELECT reservation_id FROM reservations WHERE reservation_type = ? AND event_date = ? AND event_time = ? AND status != 'rejected' LIMIT 1");
        if (!$stmt) {
            $error = 'Unable to check reservation availability.';
        } else {
            $stmt->bind_param('sss', $reservation_type, $event_date, $event_time);
            $stmt->execute();
            $conflict_result = $stmt->get_result();
            if ($conflict_result->num_rows > 0) {
                $error = 'This time slot is already reserved. Please choose another date/time.';
            }
            $stmt->close();
        }

        if (!$error) {
            $status = 'pending';
            $stmt = $conn->prepare("INSERT INTO reservations (user_id, reservation_type, event_date, event_time, event_details, status) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                $error = 'Unable to prepare your reservation request.';
            } else {
                $stmt->bind_param('isssss', $user_id, $reservation_type, $event_date, $event_time, $event_details, $status);
                if ($stmt->execute()) {
                    $reservation_id = $conn->insert_id;
                    createAuditLog($conn, $user_id, 'CREATE_RESERVATION', 'reservations', $reservation_id);
                    createNotification($conn, $user_id, 'Reservation Submitted', 'Your reservation request has been submitted for review.');
                    $success = 'Reservation request submitted successfully!';
                } else {
                    $error = 'Error submitting reservation: ' . $conn->error;
                }
                $stmt->close();
            }
        }
    }
}

$stmt = $conn->prepare("SELECT reservation_id, reservation_type, event_date, event_time, event_details, status, admin_notes, created_at FROM reservations WHERE user_id = ? ORDER BY event_date DESC, event_time DESC LIMIT 10");
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
