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
        if (isAdmin() && administratorMfaIsEnforced()) {
            $login_otp_enabled = 1;
        }
        if ($login_otp_enabled === 1
            && verifiedAuthenticationDestination($conn, (int) $user_id, 'email') === null
            && verifiedAuthenticationDestination($conn, (int) $user_id, 'mobile') === null) {
            $error = 'Verify an email address or mobile number before enabling login verification.';
        }
        $categories = ['announcements', 'requests', 'schedules', 'system'];
        if ($error === '') {
            $otpPreference = $conn->prepare('UPDATE users SET login_otp_enabled = ? WHERE id = ?');
            $otpPreference->bind_param('ii', $login_otp_enabled, $user_id);
            $otpPreference->execute();
            $otpPreference->close();
        }
        foreach ($error === '' ? $categories : [] as $category) {
            $email_enabled = isset($_POST['email_' . $category]) ? 1 : 0;
            $sms_enabled = isset($_POST['sms_' . $category]) ? 1 : 0;
            $stmt = $conn->prepare("INSERT INTO notification_preferences (user_id, category, email_enabled, sms_enabled, in_app_enabled) VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), sms_enabled = VALUES(sms_enabled), in_app_enabled = 1");
            if ($stmt) {
                $stmt->bind_param('isii', $user_id, $category, $email_enabled, $sms_enabled);
                $stmt->execute();
                $stmt->close();
            }
        }
        if ($error === '') {
            $success = 'Notification and security preferences updated.';
            createAuditLog($conn, $user_id, $login_otp_enabled ? 'ENABLE_LOGIN_OTP' : 'DISABLE_LOGIN_OTP', 'users', $user_id);
        }
        $user = getUserById($conn, $user_id);
    } else {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone_number = normalizePhilippineMobileForStorage($_POST['phone_number'] ?? '');
    $chapel_district = trim($_POST['chapel_district'] ?? '');

    if ($fullname === '' || !isValidPhilippineMobile($phone_number)) {
        $error = 'Full name and phone number are required.';
    } elseif (!authenticationIdentifierAvailable($conn, 'mobile', $phone_number, (int) $user_id)) {
        $error = 'That mobile number is not available for this account.';
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullname = ?, phone_verified_at = CASE WHEN phone_number = ? THEN phone_verified_at ELSE NULL END, phone_number = ?, chapel_district = ? WHERE id = ?");
        if (!$stmt) {
            $error = 'Unable to update profile.';
        } else {
            $stmt->bind_param('ssssi', $fullname, $phone_number, $phone_number, $chapel_district, $user_id);
        }
    }

    if (!$error && $stmt->execute()) {
        $_SESSION['fullname'] = $fullname;
        $phoneVerifiedAt = normalizePhilippineMobileForStorage($user['phone_number'] ?? '') === $phone_number
            ? ($user['phone_verified_at'] ?? null)
            : null;
        synchronizeAuthenticationIdentifier($conn, (int) $user_id, 'mobile', $phone_number, $phoneVerifiedAt);
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
$pref_result = $conn->query("SELECT category, email_enabled, sms_enabled FROM notification_preferences WHERE user_id = " . intval($user_id));
while ($pref_result && $row = $pref_result->fetch_assoc()) {
    $preferences[$row['category']] = [
        'email' => intval($row['email_enabled']),
        'sms' => intval($row['sms_enabled'])
    ];
}
foreach (['announcements', 'requests', 'schedules', 'system'] as $category) {
    if (!isset($preferences[$category])) {
        $preferences[$category] = ['email' => 1, 'sms' => 1];
    }
}

$completed_request_count = 0;
$completed_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM requests WHERE user_id = ? AND status = 'completed'");
if ($completed_stmt) {
    $completed_stmt->bind_param('i', $user_id);
    $completed_stmt->execute();
    $completed_result = $completed_stmt->get_result();
    $completed_request_count = intval($completed_result->fetch_assoc()['total'] ?? 0);
    $completed_stmt->close();
}

$profile_display_name = trim((string) ($user['fullname'] ?? 'Parishioner'));
$profile_first_name_parts = preg_split('/\s+/', $profile_display_name);
$profile_first_name = $profile_first_name_parts[0] ?? 'Parishioner';
$profile_initial = strtoupper(substr($profile_first_name, 0, 1));
$profile_district = trim((string) ($user['chapel_district'] ?? ''));
$profile_member_since = !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : 'N/A';

$page_title = 'My Profile';
?>
<?php include '../templates/header.php'; ?>

<section class="profile-mobile-hero" aria-label="Profile summary">
    <div class="profile-mobile-hero-top">
        <a href="../users/index.php" class="profile-mobile-back" aria-label="Back to dashboard">
            <i class="fas fa-chevron-left" aria-hidden="true"></i>
        </a>
        <strong>Profile</strong>
    </div>
    <div class="profile-mobile-identity">
        <span class="profile-mobile-avatar"><?php echo e($profile_initial); ?></span>
        <h1><?php echo e($profile_first_name); ?></h1>
        <p><?php echo e($profile_district !== '' ? $profile_district . ' Member' : 'Parishioner'); ?></p>
    </div>
</section>

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

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card profile-details-card" id="profileDetails">
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
                            <label for="email" class="form-label"><i class="fas fa-lock profile-email-lock" aria-hidden="true"></i> Email Address (Cannot be changed)</label>
                            <input type="email" class="form-control" id="email" value="<?php echo e($user['email']); ?>" disabled>
                            <div class="form-text profile-verification-status">
                                <span class="profile-verification-prefix">Gmail verification:</span>
                                <?php if (!empty($user['email_verified_at'])): ?>
                                    <span class="badge bg-success profile-verification-pill"><i class="fas fa-check" aria-hidden="true"></i> Verified <?php echo e(formatDateTime($user['email_verified_at'])); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark profile-verification-pill">Not verified</span>
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

                        <div class="mb-3 profile-member-since-field">
                            <label class="form-label">Member Since</label>
                            <input type="text" class="form-control" value="<?php echo formatDate($user['created_at']); ?>" disabled>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?php echo isAdmin() ? '../admin/dashboard.php' : '../users/index.php'; ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div class="profile-settings-section">
                        <h6><i class="fas fa-bell"></i> Notification and Security Preferences</h6>
                        <form method="POST" class="row g-3">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="preferences">
                            <div class="col-12">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="login_otp_enabled" <?php echo ((isAdmin() && administratorMfaIsEnforced()) || !empty($user['login_otp_enabled'])) ? 'checked' : ''; ?> <?php echo (isAdmin() && administratorMfaIsEnforced()) ? 'disabled' : ''; ?>>
                                    <?php if (isAdmin() && administratorMfaIsEnforced()): ?><input type="hidden" name="login_otp_enabled" value="1"><?php endif; ?>
                                    <span class="form-check-label"><?php echo (isAdmin() && administratorMfaIsEnforced()) ? 'Login OTP is mandatory for administrators' : 'Require OTP after password login'; ?></span>
                                    <?php if (isAdmin() && !administratorMfaIsEnforced()): ?><span class="form-text d-block">Administrator OTP is optional during local development and mandatory in production.</span><?php endif; ?>
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
                                        <input class="form-check-input" type="checkbox" name="email_<?php echo e($key); ?>" <?php echo !empty($preferences[$key]['email']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label"><?php echo e($label); ?> emails</span>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="sms_<?php echo e($key); ?>" <?php echo !empty($preferences[$key]['sms']) ? 'checked' : ''; ?>>
                                        <span class="form-check-label"><?php echo e($label); ?> SMS</span>
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
                    <div class="profile-change-password-section">
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

<a href="logout.php" class="profile-mobile-inline-logout">
    <i class="fas fa-power-off" aria-hidden="true"></i>
    <span>Log Out</span>
</a>

<?php include '../templates/footer.php'; ?>
