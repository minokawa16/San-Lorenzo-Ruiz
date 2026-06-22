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
$error = '';
$success = '';

// Handle profile update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'preferences') {
        $login_otp_enabled = isset($_POST['login_otp_enabled']) ? 1 : 0;
        $categories = ['announcements', 'requests', 'schedules', 'system'];
        $conn->query("UPDATE users SET login_otp_enabled = $login_otp_enabled WHERE id = " . intval($user_id));
        foreach ($categories as $category) {
            $enabled = isset($_POST['email_' . $category]) ? 1 : 0;
            $stmt = $conn->prepare("INSERT INTO notification_preferences (user_id, category, email_enabled, in_app_enabled) VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), in_app_enabled = 1");
            if ($stmt) {
                $stmt->bind_param('isi', $user_id, $category, $enabled);
                $stmt->execute();
                $stmt->close();
            }
        }
        $success = 'Notification and security preferences updated.';
        $user = getUserById($conn, $user_id);
    } else {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $chapel_district = trim($_POST['chapel_district'] ?? '');

    if ($fullname === '' || $phone_number === '') {
        $error = 'Full name and phone number are required.';
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullname = ?, phone_number = ?, chapel_district = ? WHERE id = ?");
        if (!$stmt) {
            $error = 'Unable to update profile.';
        } else {
            $stmt->bind_param('sssi', $fullname, $phone_number, $chapel_district, $user_id);
        }
    }

    if (!$error && $stmt->execute()) {
        $_SESSION['fullname'] = $fullname;
        $success = 'Profile updated successfully!';
        $user = getUserById($conn, $user_id);
    } else {
        $error = $error ?: 'Error updating profile: ' . $conn->error;
    }

    if (isset($stmt) && $stmt) {
        $stmt->close();
    }
    }
}

$preferences = [];
$pref_result = $conn->query("SELECT category, email_enabled FROM notification_preferences WHERE user_id = " . intval($user_id));
while ($pref_result && $row = $pref_result->fetch_assoc()) {
    $preferences[$row['category']] = intval($row['email_enabled']);
}
foreach (['announcements', 'requests', 'schedules', 'system'] as $category) {
    if (!isset($preferences[$category])) {
        $preferences[$category] = 1;
    }
}

$page_title = 'My Profile';
?>
<?php include '../templates/header.php'; ?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-user"></i> My Profile</h5>
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
                            <label for="email" class="form-label">Email Address (Cannot be changed)</label>
                            <input type="email" class="form-control" id="email" value="<?php echo e($user['email']); ?>" disabled>
                            <div class="form-text">
                                Gmail verification:
                                <?php if (!empty($user['email_verified_at'])): ?>
                                    <span class="badge bg-success">Verified <?php echo e(formatDateTime($user['email_verified_at'])); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Not verified</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="phone_number" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone_number" name="phone_number" 
                                   value="<?php echo e($user['phone_number'] ?? ''); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="chapel_district" class="form-label">Chapel/District</label>
                            <input type="text" class="form-control" id="chapel_district" name="chapel_district" 
                                   value="<?php echo e($user['chapel_district'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Member Since</label>
                            <input type="text" class="form-control" value="<?php echo formatDate($user['created_at']); ?>" disabled>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?php echo isAdmin() ? '../admin/dashboard.php' : '../users/dashboard.php'; ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div>
                        <h6><i class="fas fa-bell"></i> Notification and Security Preferences</h6>
                        <form method="POST" class="row g-3">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="preferences">
                            <div class="col-12">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="login_otp_enabled" <?php echo !empty($user['login_otp_enabled']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Require email OTP after password login</span>
                                </label>
                            </div>
                            <?php
                            $labels = [
                                'announcements' => 'Parish announcements',
                                'requests' => 'Request status updates',
                                'schedules' => 'Schedule and reservation updates',
                                'system' => 'System and verification notices'
                            ];
                            ?>
                            <?php foreach ($labels as $key => $label): ?>
                                <div class="col-md-6">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="email_<?php echo e($key); ?>" <?php echo !empty($preferences[$key]) ? 'checked' : ''; ?>>
                                        <span class="form-check-label"><?php echo e($label); ?> emails</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div class="col-12">
                                <button type="submit" class="btn btn-outline-primary"><i class="fas fa-save"></i> Save Preferences</button>
                            </div>
                        </form>
                    </div>

                    <hr class="my-4">

                    <!-- Change Password Section -->
                    <div>
                        <h6><i class="fas fa-key"></i> Change Password</h6>
                        <form action="change-password.php" method="POST">
                            <?php echo csrfInput(); ?>
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password">
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password">
                            </div>
                            <div class="mb-3">
                                <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password">
                            </div>
                            <button type="submit" class="btn btn-warning">Change Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
