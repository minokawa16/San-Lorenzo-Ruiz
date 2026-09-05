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
            'reservation_type' => $_POST['reservation_type'] ?? '',
            'start_at' => (($_POST['event_date'] ?? '') . ' ' . ($_POST['event_time'] ?? '') . ':00'),
            'service_duration_minutes' => (int) ($_POST['service_duration_minutes'] ?? 60),
            'setup_duration_minutes' => (int) ($_POST['setup_duration_minutes'] ?? 15),
            'cleanup_duration_minutes' => (int) ($_POST['cleanup_duration_minutes'] ?? 15),
            'event_details' => $_POST['event_details'] ?? '',
            'resource_ids' => $_POST['resource_ids'] ?? [],
        ], (string) ($_POST['idempotency_key'] ?? ''));
        $success = 'Reservation request submitted successfully!';
        $idempotency_key = hash('sha256', random_bytes(32));
    } catch (Throwable $e) {
        http_response_code(409);
        $error = '<strong>Reservation Conflict (HTTP 409):</strong> ' . e($e->getMessage());
        if ($e instanceof DomainException && !empty($_POST['event_date']) && !empty($_POST['event_time']) && !empty($_POST['resource_ids'])) {
            try {
                $suggestions = (new ResourceAvailabilityService($conn))->suggestAvailableSlots(
                    (array) $_POST['resource_ids'],
                    $_POST['event_date'] . ' ' . $_POST['event_time'] . ':00',
                    (int) ($_POST['service_duration_minutes'] ?? 60),
                    (int) ($_POST['setup_duration_minutes'] ?? 15),
                    (int) ($_POST['cleanup_duration_minutes'] ?? 15)
                );
                if ($suggestions) {
                    $error .= '<div class="mt-2"><strong>Suggested Available Alternatives:</strong><ul class="mb-0 mt-1">';
                    foreach ($suggestions as $slot) {
                        $error .= '<li>' . date('l, F j, Y — g:i A', strtotime($slot['start_at'])) . '</li>';
                    }
                    $error .= '</ul></div>';
                }
            } catch (Throwable $ignored) {}
        }
    }
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

                    <form method="POST" action="" id="reservationForm">
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
                                <input type="date" class="form-control form-control-lg" id="event_date" name="event_date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="event_time" class="form-label">Event Time</label>
                                <input type="time" class="form-control form-control-lg" id="event_time" name="event_time" required>
                            </div>
                        </div>

                        <!-- Real-Time Dynamic Slot Picker -->
                        <div class="card bg-light border-0 shadow-sm p-3 mb-3" id="calendarSlotSection">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-dark"><i class="fas fa-calendar-check text-warning me-1"></i> Available Real-Time Calendar Slots</span>
                                <span class="badge bg-white text-secondary border px-2 py-1" id="slotLoadingBadge" style="display:none;">
                                    <i class="fas fa-spinner fa-spin me-1"></i> Checking availability...
                                </span>
                            </div>
                            <div class="small text-muted mb-2">
                                Click an available slot below to auto-fill the event time. Occupied slots and 30-minute transition buffers are automatically greyed out and disabled.
                            </div>
                            <div class="d-flex flex-wrap gap-2" id="slotGridContainer">
                                <div class="text-muted small py-1" id="slotPlaceholderText">
                                    <i class="fas fa-arrow-up me-1"></i> Select an Event Date above to load real-time slot availability.
                                </div>
                            </div>
                            <div id="slotConflictAlert" class="alert alert-warning mt-2 mb-0 py-2 px-3 small" style="display:none;">
                                <i class="fas fa-triangle-exclamation text-danger me-1"></i>
                                <span id="slotConflictMsg"></span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="service_duration_minutes">Service (minutes)</label>
                                <input class="form-control" type="number" id="service_duration_minutes" name="service_duration_minutes" min="15" max="1440" value="60" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="setup_duration_minutes">Setup Buffer (minutes)</label>
                                <input class="form-control" type="number" id="setup_duration_minutes" name="setup_duration_minutes" min="0" max="1440" value="15" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="cleanup_duration_minutes">Cleanup Buffer (minutes)</label>
                                <input class="form-control" type="number" id="cleanup_duration_minutes" name="cleanup_duration_minutes" min="0" max="1440" value="15" required>
                            </div>
                            <div class="col-12 mt-0 mb-3">
                                <small class="text-muted"><i class="fas fa-shield-halved text-success me-1"></i> A 30-minute transition buffer (15m setup + 15m cleanup) is enforced between services to eliminate double-booking.</small>
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
                            <button type="submit" class="btn btn-primary" id="submitReservationBtn">Submit Reservation</button>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('reservationForm');
    const submitBtn = document.getElementById('submitReservationBtn');
    const dateInput = document.getElementById('event_date');
    const timeInput = document.getElementById('event_time');
    const resourceSelect = document.getElementById('resource_ids');
    const durationInput = document.getElementById('service_duration_minutes');
    const setupInput = document.getElementById('setup_duration_minutes');
    const cleanupInput = document.getElementById('cleanup_duration_minutes');
    const slotContainer = document.getElementById('slotGridContainer');
    const slotLoadingBadge = document.getElementById('slotLoadingBadge');
    const conflictAlert = document.getElementById('slotConflictAlert');
    const conflictMsg = document.getElementById('slotConflictMsg');

    let currentOccupiedWindows = [];
    let currentSlots = [];

    function fetchAvailability() {
        if (!dateInput || !dateInput.value) return;
        const selectedResources = resourceSelect ? Array.from(resourceSelect.selectedOptions).map(o => o.value) : [];
        const durationVal = durationInput ? (parseInt(durationInput.value, 10) || 60) : 60;
        const bufferVal = ((parseInt(setupInput ? setupInput.value : 15, 10) || 15) + (parseInt(cleanupInput ? cleanupInput.value : 15, 10) || 15));

        if (slotLoadingBadge) slotLoadingBadge.style.display = 'inline-block';

        const url = '../api/calendar-availability.php?date=' + encodeURIComponent(dateInput.value) 
            + '&resource_ids=' + encodeURIComponent(selectedResources.join(','))
            + '&duration=' + encodeURIComponent(durationVal)
            + '&buffer=' + encodeURIComponent(bufferVal);

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (slotLoadingBadge) slotLoadingBadge.style.display = 'none';
                if (!data.success) return;
                currentSlots = data.slots || [];
                currentOccupiedWindows = data.occupied_intervals || [];
                renderSlots(currentSlots);
                validateCurrentTime();
            })
            .catch(() => {
                if (slotLoadingBadge) slotLoadingBadge.style.display = 'none';
            });
    }

    function renderSlots(slots) {
        if (!slotContainer) return;
        slotContainer.innerHTML = '';

        if (!slots || slots.length === 0) {
            slotContainer.innerHTML = '<div class="text-muted small py-1"><i class="fas fa-info-circle me-1"></i> No standard slots available for this date.</div>';
            return;
        }

        slots.forEach(slot => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm d-inline-flex align-items-center gap-1 ' + 
                (slot.available ? 'btn-outline-success slot-available-btn' : 'btn-outline-secondary text-muted slot-disabled-btn');

            let badgeHtml = '';
            if (slot.is_occupied) {
                badgeHtml = '<span class="badge bg-danger ms-1" style="font-size:0.68rem;">Occupied</span>';
                btn.disabled = true;
                btn.title = 'This slot is already booked.';
                btn.style.textDecoration = 'line-through';
                btn.style.opacity = '0.6';
                btn.style.cursor = 'not-allowed';
            } else if (slot.is_buffer_conflict) {
                badgeHtml = '<span class="badge bg-secondary ms-1" style="font-size:0.68rem;">30m Buffer</span>';
                btn.disabled = true;
                btn.title = '30-minute transition buffer between services.';
                btn.style.opacity = '0.6';
                btn.style.cursor = 'not-allowed';
            } else if (slot.is_past) {
                badgeHtml = '<span class="badge bg-light text-dark border ms-1" style="font-size:0.68rem;">Past</span>';
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            } else {
                badgeHtml = '<span class="badge bg-success-subtle text-success ms-1" style="font-size:0.68rem;">Available</span>';
            }

            btn.innerHTML = `<i class="far ${slot.available ? 'fa-clock text-success' : 'fa-circle-xmark text-danger'} me-1"></i>` +
                            `<strong>${slot.start_display}</strong> - ${slot.end_display}` + badgeHtml;

            if (slot.available) {
                btn.addEventListener('click', function() {
                    timeInput.value = slot.time;
                    highlightSelectedSlot(slot.time);
                    validateCurrentTime();
                });
            }

            if (timeInput && timeInput.value === slot.time && slot.available) {
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-success', 'text-white');
            }

            slotContainer.appendChild(btn);
        });
    }

    function highlightSelectedSlot(selectedTime) {
        if (!slotContainer) return;
        const buttons = slotContainer.querySelectorAll('.slot-available-btn');
        buttons.forEach(b => {
            if (b.textContent.includes(selectedTime)) {
                b.classList.remove('btn-outline-success');
                b.classList.add('btn-success', 'text-white');
            } else {
                b.classList.remove('btn-success', 'text-white');
                b.classList.add('btn-outline-success');
            }
        });
    }

    function validateCurrentTime() {
        if (!timeInput || !timeInput.value || !dateInput || !dateInput.value) {
            if (conflictAlert) conflictAlert.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
            return;
        }

        const chosenSlot = currentSlots.find(s => s.time === timeInput.value);
        if (chosenSlot && !chosenSlot.available) {
            if (conflictAlert && conflictMsg) {
                conflictMsg.textContent = `The selected time (${chosenSlot.start_display}) is ${chosenSlot.reason}. Please select an available slot above.`;
                conflictAlert.style.display = 'block';
            }
            if (submitBtn) submitBtn.disabled = true;
            return;
        }

        const chosenStartTs = new Date(dateInput.value + 'T' + timeInput.value).getTime();
        const durationVal = durationInput ? (parseInt(durationInput.value, 10) || 60) : 60;
        const chosenEndTs = chosenStartTs + (durationVal * 60 * 1000);

        let conflict = false;
        let reason = '';

        for (const interval of currentOccupiedWindows) {
            const bufStart = new Date(dateInput.value + 'T' + interval.buffer_start).getTime();
            const bufEnd = new Date(dateInput.value + 'T' + interval.buffer_end).getTime();
            if (chosenStartTs < bufEnd && chosenEndTs > bufStart) {
                conflict = true;
                reason = `Selected time conflicts with ${interval.type} (${interval.start_display} - ${interval.end_display}) or its transition buffer.`;
                break;
            }
        }

        if (conflict) {
            if (conflictAlert && conflictMsg) {
                conflictMsg.textContent = reason + ' Please choose an available time slot.';
                conflictAlert.style.display = 'block';
            }
            if (submitBtn) submitBtn.disabled = true;
        } else {
            if (conflictAlert) conflictAlert.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    if (dateInput) {
        dateInput.addEventListener('change', fetchAvailability);
    }
    if (resourceSelect) {
        resourceSelect.addEventListener('change', fetchAvailability);
    }
    if (durationInput) {
        durationInput.addEventListener('change', fetchAvailability);
    }
    if (setupInput) {
        setupInput.addEventListener('change', fetchAvailability);
    }
    if (cleanupInput) {
        cleanupInput.addEventListener('change', fetchAvailability);
    }
    if (timeInput) {
        timeInput.addEventListener('change', validateCurrentTime);
        timeInput.addEventListener('input', validateCurrentTime);
    }

    if (dateInput && dateInput.value) {
        fetchAvailability();
    }

    if (form && submitBtn) {
        form.addEventListener('submit', function(e) {
            if (form.dataset.submitting === 'true') {
                e.preventDefault();
                return false;
            }
            if (form.checkValidity()) {
                form.dataset.submitting = 'true';
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Submitting Reservation...';
            }
        });
    }
});
</script>

<?php include '../templates/footer.php'; ?>
