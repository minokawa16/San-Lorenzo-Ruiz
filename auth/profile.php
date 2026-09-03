<?php
/**
 * User Profile Module - Handles profile details, preferences, and account settings.
 */
// Include centralized session management
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
ensureEmailNotificationSchema($conn);

$user_id = $_SESSION['user_id'];
$user = getUserById($conn, $user_id);
$is_admin = isAdmin();
$error = '';
$success = '';

// Handle profile update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
    requireValidCsrfToken();
    $fullname = trim($_POST['fullname'] ?? '');

    if ($fullname === '') {
        $error = 'Full name is required.';
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullname = ? WHERE id = ?");
        if (!$stmt) {
            $error = 'Unable to update profile.';
        } else {
            $stmt->bind_param('si', $fullname, $user_id);
            if ($stmt->execute()) {
                $_SESSION['fullname'] = $fullname;
                $success = 'Profile updated successfully!';
                $user = getUserById($conn, $user_id);
            } else {
                $error = 'Error updating profile: ' . $conn->error;
            }
            $stmt->close();
        }
    }
}

$completed_request_count = 0;
if (!$is_admin) {
    $completed_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM requests WHERE user_id = ? AND status = 'completed'");
    if ($completed_stmt) {
        $completed_stmt->bind_param('i', $user_id);
        $completed_stmt->execute();
        $completed_result = $completed_stmt->get_result();
        $completed_request_count = intval($completed_result->fetch_assoc()['total'] ?? 0);
        $completed_stmt->close();
    }
}

$profile_display_name = trim((string) ($user['fullname'] ?? ($is_admin ? 'Administrator' : 'Parishioner')));
$profile_first_name_parts = preg_split('/\s+/', $profile_display_name);
$profile_first_name = $profile_first_name_parts[0] ?? ($is_admin ? 'Admin' : 'Parishioner');
$profile_initial = strtoupper(substr($profile_first_name, 0, 1));
$profile_district = trim((string) ($user['chapel_district'] ?? ''));
$profile_member_since = !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : 'N/A';

$page_title = $is_admin ? 'Profile Settings' : 'My Profile';
?>
<?php include '../templates/header.php'; ?>

<section class="profile-mobile-hero" aria-label="Profile summary">
    <div class="profile-mobile-hero-top">
        <a href="<?php echo $is_admin ? '../admin/dashboard.php' : '../users/index.php'; ?>" class="profile-mobile-back" aria-label="Back to dashboard">
            <i class="fas fa-chevron-left" aria-hidden="true"></i>
        </a>
        <strong><?php echo $is_admin ? 'Profile Settings' : 'Profile'; ?></strong>
    </div>
    <div class="profile-mobile-identity">
        <span class="profile-mobile-avatar"><?php echo e($profile_initial); ?></span>
        <h1><?php echo e($profile_first_name); ?></h1>
        <p><?php echo $is_admin ? 'Administrator' : e($profile_district !== '' ? $profile_district . ' Member' : 'Parishioner'); ?></p>
    </div>
</section>

<?php if (!$is_admin): ?>
<div class="profile-mobile-stack">
    <section class="profile-mobile-stats" aria-label="Profile statistics">
        <div>
            <strong><?php echo intval($completed_request_count); ?></strong>
            <span>Completed</span>
        </div>
        <div>
            <strong><?php echo e($profile_member_since); ?></strong>
            <span>Member Since</span>
        </div>
    </section>
</div>
<?php endif; ?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card profile-details-card" id="profileDetails">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas <?php echo $is_admin ? 'fa-user-shield' : 'fa-user'; ?>"></i> <?php echo $is_admin ? 'Profile Settings' : 'My Profile'; ?></h5>
                </div>
                <div class="card-body">
                    
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

                    <form method="POST" action="">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="profile">
                        <div class="mb-3">
                            <label for="fullname" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="fullname" name="fullname" 
                                   value="<?php echo e($user['fullname']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label"><i class="fas fa-lock profile-email-lock" aria-hidden="true"></i> Email Address (Cannot be changed)</label>
                            <input type="email" class="form-control" id="email" value="<?php echo e($user['email']); ?>" disabled>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?php echo $is_admin ? '../admin/dashboard.php' : '../users/index.php'; ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <!-- Change Password Section -->
                    <div class="profile-change-password-section">
                        <h6 class="fw-bold mb-3" style="color: #344536;"><i class="fas fa-key me-2" style="color: #c9a646;"></i> Change Password</h6>
                        <form action="change-password.php" method="POST">
                            <?php echo csrfInput(); ?>
                            <div class="mb-3">
                                <label for="current_password" class="form-label fw-semibold text-secondary small">Current Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control rounded-start-3" id="current_password" name="current_password" required placeholder="Enter current password">
                                    <button class="btn btn-outline-secondary rounded-end-3 toggle-password-btn" type="button" data-toggle-password="current_password" aria-label="Show password" title="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label fw-semibold text-secondary small">New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control rounded-start-3" id="new_password" name="new_password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required placeholder="Enter new password">
                                    <button class="btn btn-outline-secondary rounded-end-3 toggle-password-btn" type="button" data-toggle-password="new_password" aria-label="Show password" title="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text text-muted small mt-1">
                                    Must be at least 8 characters with uppercase, lowercase, numbers, and symbols.
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="confirm_new_password" class="form-label fw-semibold text-secondary small">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control rounded-start-3" id="confirm_new_password" name="confirm_new_password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required placeholder="Repeat new password">
                                    <button class="btn btn-outline-secondary rounded-end-3 toggle-password-btn" type="button" data-toggle-password="confirm_new_password" aria-label="Show password" title="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn text-white px-4 py-2 rounded-pill fw-medium" style="background-color: #b8860b; border: none;">Change Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<a href="logout.php" class="profile-mobile-inline-logout">
    <i class="fas fa-power-off" aria-hidden="true"></i>
    <span>Log Out</span>
</a>

<style>
.toggle-password-btn {
    border-color: #ced4da;
    background-color: #fff;
    color: #6c757d;
    transition: all 0.15s ease;
}
.toggle-password-btn:hover, .toggle-password-btn:focus {
    background-color: #f8f9fa;
    border-color: #b8860b;
    color: #b8860b;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-toggle-password]').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const targetId = toggle.dataset.togglePassword || toggle.getAttribute('data-toggle-password');
            const target = document.getElementById(targetId);
            if (!target) return;
            const icon = toggle.querySelector('i');
            const isPassword = target.type === 'password';
            target.type = isPassword ? 'text' : 'password';
            if (icon) {
                icon.classList.remove(isPassword ? 'fa-eye' : 'fa-eye-slash');
                icon.classList.add(isPassword ? 'fa-eye-slash' : 'fa-eye');
            }
            const newLabel = isPassword ? 'Hide password' : 'Show password';
            toggle.setAttribute('aria-label', newLabel);
            toggle.setAttribute('title', newLabel);
        });
    });
});
</script>

<?php include '../templates/footer.php'; ?>
