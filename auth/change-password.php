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
    $confirm_new_password = $_POST['confirm_new_password'] ?? '';

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
?>
<?php include '../templates/header.php'; ?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-key"></i> Change Password</h5>
                </div>
                <div class="card-body">
                    <?php if ($rotation_pending && !passwordChangeIsEnforced()): ?>
                        <div class="alert alert-warning" role="status">
                            Password replacement is required before production deployment, but local development navigation remains available.
                        </div>
                    <?php endif; ?>
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
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                            <div class="form-text"><?php echo e(passwordRequirementsMessage()); ?></div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                        </div>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?php echo e(getUserDashboardURL()); ?>" class="btn btn-outline-secondary">Return to Dashboard</a>
                            <button type="submit" class="btn btn-warning">Change Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
