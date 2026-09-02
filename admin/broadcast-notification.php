<?php
/**
 * Parish-Wide & Targeted Notification Broadcast Module
 * Admin interface for sending parish announcements and targeted notices.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/NotificationService.php';

requireAdmin();
requirePermission('announcements.manage');
ensureEmailNotificationSchema($conn);

$error = '';
$success = '';
$dispatched_count = 0;

// Fetch list of distinct chapel districts
$districts = [];
$d_res = $conn->query("SELECT DISTINCT chapel_district FROM users WHERE chapel_district IS NOT NULL AND chapel_district <> '' ORDER BY chapel_district ASC");
while ($d_res && $d_row = $d_res->fetch_assoc()) {
    $districts[] = $d_row['chapel_district'];
}

// Fetch total active parishioners count
$active_count_res = $conn->query("SELECT COUNT(*) AS count FROM users WHERE (role IN ('user', 'parishioner', 'member') OR role IS NULL OR role = '') AND status = 'active'");
$total_active_parishioners = $active_count_res ? intval($active_count_res->fetch_assoc()['count'] ?? 0) : 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $category = trim($_POST['category'] ?? 'announcements');
    $audience = trim($_POST['audience'] ?? 'all');
    $target_district = trim($_POST['chapel_district'] ?? '');
    $target_user_id = intval($_POST['target_user_id'] ?? 0);

    $allowed_categories = ['announcements', 'schedules', 'system'];
    if (!in_array($category, $allowed_categories, true)) {
        $category = 'announcements';
    }

    if (mb_strlen($title) < 4) {
        $error = 'Please provide a descriptive notification title (at least 4 characters).';
    } elseif (mb_strlen($message) < 10) {
        $error = 'Please provide meaningful notification message content (at least 10 characters).';
    } elseif ($audience === 'district' && $target_district === '') {
        $error = 'Please select a chapel / district for this targeted broadcast.';
    } elseif ($audience === 'user' && $target_user_id <= 0) {
        $error = 'Please select a specific parishioner recipient.';
    } else {
        $options = [];
        if ($audience === 'district') {
            $options['chapel_district'] = $target_district;
        } elseif ($audience === 'user') {
            $options['user_id'] = $target_user_id;
        }

        $result = notifyAllActiveParishioners($conn, $title, $message, $category, $options);
        $dispatched_count = (int) ($result['count'] ?? 0);

        if ($dispatched_count > 0) {
            $success = "Successfully broadcasted notification to $dispatched_count active parishioner(s) across enabled channels (In-System, Email, SMS)!";
            if (function_exists('createAuditLog')) {
                createAuditLog($conn, $_SESSION['user_id'], 'BROADCAST_NOTIFICATION', 'notifications', null, null, [
                    'title' => $title,
                    'category' => $category,
                    'audience' => $audience,
                    'recipient_count' => $dispatched_count
                ]);
            }
        } else {
            $error = 'No active parishioners matched the selected audience criteria.';
        }
    }
}

$page_title = 'Broadcast Notification';
include '../templates/header.php';
?>

<div class="container-fluid py-4" style="max-width: 960px;">
    <!-- Standardized Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1" style="color: #344536;">
                <i class="fas fa-bullhorn me-2" style="color: #c9a646;"></i> Broadcast Notification
            </h1>
            <p class="text-muted mb-0">Publish parish announcements or broadcast alerts to active parishioners.</p>
        </div>
        <div>
            <a href="manage-announcements.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Announcements
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-exclamation me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-check me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header py-3 px-4" style="background: linear-gradient(135deg, #344536 0%, #202d22 100%); color: #fff;">
            <div class="d-flex align-items-center justify-content-between">
                <span class="fw-bold fs-6"><i class="fas fa-paper-plane me-2" style="color: #c9a646;"></i> Compose Broadcast</span>
                <span class="badge" style="background: rgba(201, 166, 70, 0.25); color: #f8f5ed; border: 1px solid rgba(201, 166, 70, 0.4);">
                    <i class="fas fa-users me-1"></i> <?php echo number_format($total_active_parishioners); ?> Active Parishioners
                </span>
            </div>
        </div>
        <div class="card-body p-4 bg-white">
            <form id="broadcastForm" method="POST">
                <?php echo csrfInput(); ?>

                <div class="mb-3">
                    <label for="broadcast_title" class="form-label fw-bold" style="color: #344536;">Notification Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-lg rounded-3" id="broadcast_title" name="title" placeholder="e.g., Solemnity of San Lorenzo Ruiz Fiesta Mass Schedule" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="broadcast_category" class="form-label fw-bold" style="color: #344536;">Category</label>
                        <select class="form-select rounded-3" id="broadcast_category" name="category">
                            <option value="announcements">🔔 Parish Announcements & Events</option>
                            <option value="schedules">📅 Schedule & Reservation Notice</option>
                            <option value="system">🔐 System & Pastoral Notice</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="broadcast_audience" class="form-label fw-bold" style="color: #344536;">Target Audience</label>
                        <select class="form-select rounded-3" id="broadcast_audience" name="audience">
                            <option value="all">👥 All Active Parishioners (<?php echo number_format($total_active_parishioners); ?> total)</option>
                            <option value="district">⛪ Specific Chapel / District</option>
                            <option value="user">👤 Specific Parishioner</option>
                        </select>
                    </div>
                </div>

                <!-- District Filter (shown only if audience === 'district') -->
                <div class="mb-3 d-none" id="districtGroup">
                    <label for="chapel_district" class="form-label fw-bold" style="color: #344536;">Select Chapel / District</label>
                    <select class="form-select rounded-3" id="chapel_district" name="chapel_district">
                        <option value="">-- Choose Chapel / District --</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- User ID Filter (shown only if audience === 'user') -->
                <div class="mb-3 d-none" id="userGroup">
                    <label for="target_user_id" class="form-label fw-bold" style="color: #344536;">Parishioner User ID</label>
                    <input type="number" class="form-control rounded-3" id="target_user_id" name="target_user_id" placeholder="Enter Parishioner Account ID">
                </div>

                <div class="mb-4">
                    <label for="broadcast_message" class="form-label fw-bold" style="color: #344536;">Notification Message <span class="text-danger">*</span></label>
                    <textarea class="form-control rounded-3" id="broadcast_message" name="message" rows="5" placeholder="Enter the complete notification message to be delivered to parishioners..." required></textarea>
                    <div class="form-text text-muted">
                        This notification will create an in-app alert for all eligible parishioners and deliver via Email / SMS according to each recipient's enabled preferences.
                    </div>
                </div>

                <div class="card rounded-3 p-3 mb-4" style="background: #fbf9f4; border: 1px solid #e8e2d5;">
                    <h6 class="fw-bold mb-2" style="color: #344536;"><i class="fas fa-circle-info text-primary me-2"></i> Delivery Rules & Safeguards</h6>
                    <ul class="mb-0 text-muted small ps-3">
                        <li>Every active, approved parishioner will receive the in-system alert in their notifications inbox.</li>
                        <li>Email and SMS notifications are automatically checked against each parishioner's personal preferences.</li>
                        <li>Inactive, rejected, pending, or disabled accounts will NOT receive the broadcast.</li>
                    </ul>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="manage-announcements.php" class="btn btn-outline-secondary px-4">Cancel</a>
                    <button type="button" class="btn btn-primary px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#confirmBroadcastModal" style="background: #344536; border-color: #2b392d;">
                        <i class="fas fa-paper-plane me-1"></i> Send Broadcast
                    </button>
                </div>

                <!-- Confirmation Modal -->
                <div class="modal fade" id="confirmBroadcastModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content rounded-4 border-0 shadow">
                            <div class="modal-header py-3" style="background: #344536; color: #fff;">
                                <h5 class="modal-title fs-6 fw-bold" id="confirmModalLabel">
                                    <i class="fas fa-triangle-exclamation me-2" style="color: #c9a646;"></i> Confirm Parish Notification Broadcast
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <p class="mb-3">Are you sure you want to broadcast this notification?</p>
                                <div class="p-3 rounded-3" style="background: #f8f5ed; border: 1px solid #e8e2d5;">
                                    <div class="fw-bold text-dark mb-1" id="previewTitle">—</div>
                                    <div class="text-muted small" id="previewAudience">—</div>
                                </div>
                            </div>
                            <div class="modal-footer border-0">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Go Back</button>
                                <button type="button" class="btn btn-primary fw-bold" id="confirmSendBtn" style="background: #344536; border-color: #2b392d;">
                                    <i class="fas fa-paper-plane me-1"></i> Confirm & Send Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var audienceSelect = document.getElementById('broadcast_audience');
    var districtGroup = document.getElementById('districtGroup');
    var userGroup = document.getElementById('userGroup');
    var broadcastForm = document.getElementById('broadcastForm');
    var confirmSendBtn = document.getElementById('confirmSendBtn');
    var previewTitle = document.getElementById('previewTitle');
    var previewAudience = document.getElementById('previewAudience');
    var titleInput = document.getElementById('broadcast_title');

    audienceSelect.addEventListener('change', function () {
        districtGroup.classList.toggle('d-none', this.value !== 'district');
        userGroup.classList.toggle('d-none', this.value !== 'user');
    });

    document.querySelector('[data-bs-target="#confirmBroadcastModal"]').addEventListener('click', function () {
        previewTitle.textContent = titleInput.value || '(No Title Entered)';
        var audText = audienceSelect.options[audienceSelect.selectedIndex].text;
        previewAudience.textContent = 'Target Audience: ' + audText;
    });

    confirmSendBtn.addEventListener('click', function () {
        confirmSendBtn.disabled = true;
        confirmSendBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Broadcasting...';
        broadcastForm.submit();
    });
});
</script>

<?php include '../templates/footer.php'; ?>
