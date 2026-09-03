<?php
/**
 * Password Change Module - Lets authenticated users update their account password securely.
 */
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();

$user_id = intval($_SESSION['user_id']);
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_new_password = $_POST['confirm_password'] ?? $_POST['confirm_new_password'] ?? '';

    if ($current_password === '' || $new_password === '' || $confirm_new_password === '') {
        $error = 'Please complete all password fields.';
    } elseif ($new_password !== $confirm_new_password) {
        $error = 'New password and confirmation do not match.';
    } elseif (!isValidPassword($new_password)) {
        $error = passwordRequirementsMessage();
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $error = 'Unable to verify your account.';
        } else {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$user || !verifyPassword($current_password, $user['password'])) {
                $error = 'Current password is incorrect.';
            } else {
                $changed = updateAccountPassword(
                    $conn,
                    $user_id,
                    $new_password,
                    !empty($_SESSION['must_change_password']) ? 'temporary_password_change' : 'authenticated_change',
                    $user_id,
                    !empty($_SESSION['must_change_password']) ? 'temporary_password_replaced' : 'password_changed'
                );
                if (!empty($changed['ok'])) {
                    createAuditLog($conn, $user_id, 'CHANGE_PASSWORD', 'users', $user_id);
                    createNotification($conn, $user_id, 'Password Changed', 'Your account password was changed successfully.');
                    $success = 'Password changed successfully.';
                } else {
                    $error = $changed['error'] ?? 'Unable to update password.';
                }
            }
        }
    }
}

$page_title = 'Change Password';
$rotation_user = getAuthenticatedUser($conn);
$rotation_pending = !empty($_SESSION['must_change_password']) || !empty($rotation_user['must_change_password']);
$dashboard_url = getUserDashboardURL();
?>
<?php include '../templates/header.php'; ?>

<div class="container py-4 my-3">
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="card p-4 shadow-sm border-0 rounded-4" style="max-width: 540px; margin: 0 auto; background: #fff;">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show rounded-3 mb-3">
                        <i class="fas fa-circle-exclamation me-1"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show rounded-3 mb-3">
                        <i class="fas fa-circle-check me-1"></i> <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?php echo csrfInput(); ?>
                    <div class="mb-3">
                        <label for="current_password" class="form-label fw-semibold text-secondary small">Current Password</label>
                        <div class="input-group">
                            <input type="password" id="current_password" name="current_password" class="form-control rounded-start-3 py-2" required placeholder="Enter current password">
                            <button class="btn btn-outline-secondary rounded-end-3 toggle-password-btn" type="button" data-toggle-password="current_password" aria-label="Show password" title="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-semibold text-secondary small">New Password</label>
                        <div class="input-group">
                            <input type="password" id="new_password" name="new_password" class="form-control rounded-start-3 py-2" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required placeholder="Enter new password">
                            <button class="btn btn-outline-secondary rounded-end-3 toggle-password-btn" type="button" data-toggle-password="new_password" aria-label="Show password" title="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text text-muted small mt-1">
                            Must be at least 8 characters with uppercase, lowercase, numbers, and symbols.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label fw-semibold text-secondary small">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control rounded-start-3 py-2" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required placeholder="Repeat new password">
                            <button class="btn btn-outline-secondary rounded-end-3 toggle-password-btn" type="button" data-toggle-password="confirm_password" aria-label="Show password" title="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?php echo e($dashboard_url); ?>" class="btn btn-outline-secondary px-4 py-2 rounded-pill fw-medium">Return to Dashboard</a>
                        <button type="submit" class="btn text-white px-4 py-2 rounded-pill fw-medium" style="background-color: #b8860b; border: none;">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

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
