<?php
/**
 * Login Page
 * AI-Powered Parish Request and Sacramental Records Management System
 * Handles user authentication with proper password verification and security
 */

// Start session directly without session.php to avoid conflicts
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include other dependencies
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';
include '../includes/auth.php';
ensureUserVerificationSchema($conn);
ensureEmailNotificationSchema($conn);

// Verify database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// If already logged in, redirect to appropriate dashboard
// Check if session variables actually exist (not just the array)
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php", true, 302);
    } else {
        header("Location: ../users/dashboard.php", true, 302);
    }
    exit();
}

$error = '';
$notice = isset($_GET['registered']) ? 'Your registration is currently under review by the parish administrator. Please wait for approval before logging in.' : '';
$status_notice = '';
$status_error = '';
$email_input = '';
$status_email_input = '';
$logo_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'san-lorenzo-logo.png';
$has_logo = is_file($logo_file);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    $form_action = $_POST['form_action'] ?? 'login';

    if ($form_action === 'check_status') {
        $status_email = trim($_POST['status_email'] ?? '');
        $status_email_input = htmlspecialchars($status_email);

        if ($status_email === '') {
            $status_error = 'Please enter the email address used during registration.';
        } elseif (!isValidEmail($status_email)) {
            $status_error = 'Invalid email format.';
        } else {
            $stmt = $conn->prepare("SELECT fullname, status, rejection_reason FROM users WHERE email = ? AND role = 'user' LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $status_email);
                $stmt->execute();
                $status_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$status_user) {
                    $status_error = 'No parishioner registration was found for that email address.';
                } elseif ($status_user['status'] === 'active') {
                    $status_notice = 'Your account has been approved. You may now log in.';
                } elseif ($status_user['status'] === 'pending_verification') {
                    $status_notice = 'Your registration is still under review by the parish administrator.';
                } elseif ($status_user['status'] === 'rejected') {
                    $reason = trim((string) ($status_user['rejection_reason'] ?? ''));
                    $status_error = 'Your registration was not approved by the parish administrator.';
                    if ($reason !== '') {
                        $status_error .= ' Reason: ' . $reason;
                    }
                } else {
                    $status_error = 'Your account status is ' . ucfirst(str_replace('_', ' ', $status_user['status'])) . '. Please contact the parish office for assistance.';
                }
            } else {
                $status_error = 'Unable to check registration status right now.';
            }
        }
    } elseif ($form_action === 'resend_verification') {
        $resend_email = trim($_POST['resend_email'] ?? '');
        if ($resend_email === '' || !isValidEmail($resend_email)) {
            $status_error = 'Please enter a valid Gmail address for verification resend.';
        } else {
            $stmt = $conn->prepare("SELECT id, fullname, email, email_verified_at FROM users WHERE email = ? AND role = 'user' LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $resend_email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$user) {
                    $status_error = 'No parishioner registration was found for that email address.';
                } elseif (!empty($user['email_verified_at'])) {
                    $status_notice = 'This Gmail account is already verified.';
                } else {
                    sendEmailVerificationMessage($conn, $user['id'], $user['email'], $user['fullname']);
                    sendOtpEmail($conn, $user['id'], $user['email'], 'registration');
                    $status_notice = 'A new verification email and OTP were sent to your Gmail address.';
                }
            }
        }
    } else {
    // Get and sanitize input
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    // Store email for form repopulation (for non-sensitive display)
    $email_input = htmlspecialchars($email);
    
    // Validate input
    if (empty($email) || empty($password)) {
        $error = 'Email and password are required';
    } elseif (!isValidEmail($email)) {
        $error = 'Invalid email format';
    } else {
        $stmt = $conn->prepare("SELECT id, fullname, email, password, role, status, rejection_reason, email_verified_at, phone_verified_at, login_otp_enabled FROM users WHERE email = ? LIMIT 1");

        if (!$stmt) {
            $error = 'Database error: ' . $conn->error;
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
            
            // Check if account is active
            if ($user['status'] === 'pending_verification') {
                $verify_note = empty($user['email_verified_at']) && empty($user['phone_verified_at']) ? ' Please verify your Gmail address or mobile number first.' : '';
                $error = 'Your registration is currently under review by the parish administrator. Please wait for approval before logging in.' . $verify_note;
            } elseif ($user['status'] === 'rejected') {
                $reason = !empty($user['rejection_reason']) ? ' Reason: ' . $user['rejection_reason'] : '';
                $error = 'Your registration was not approved by the parish administrator.' . $reason;
            } elseif (empty($user['email_verified_at']) && empty($user['phone_verified_at'])) {
                $error = 'Please verify your Gmail account or mobile number before logging in. Use the resend verification option below if needed.';
            } elseif ($user['status'] !== 'active') {
                $error = 'Your account is inactive. Please contact the administrator.';
            } else {
                // Verify password using bcrypt password_verify()
                if (verifyPassword($password, $user['password'])) {
                    if (intval($user['login_otp_enabled']) === 1) {
                        sendOtpEmail($conn, $user['id'], $user['email'], 'login');
                        $_SESSION['pending_otp_user_id'] = $user['id'];
                        $_SESSION['pending_otp_email'] = $user['email'];
                        header('Location: verify-otp.php?purpose=login');
                        exit();
                    }

                    // Use auth function to set session
                    loginUser($user['id'], $user['fullname'], $user['email'], $user['role']);
                    
                    // Create audit log for successful login
                    createAuditLog($conn, $user['id'], 'LOGIN', 'users', $user['id']);
                    
                    // Role-based redirection using auth function
                    redirectAfterLogin();
                } else {
                    // Password verification failed
                    $error = 'Invalid email or password';
                }
            }
            } else {
                // Email not found
                $error = 'Invalid email or password';
            }
            $stmt->close();
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | San Lorenzo Ruiz Mission Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css">
</head>
<body class="auth-cinematic-page">
    <div class="auth-ambient" aria-hidden="true"></div>
    <main class="auth-screen auth-login-screen">
        <aside class="auth-copy" aria-label="System introduction">
            <span class="auth-copy-badge"><i class="fas fa-wand-magic-sparkles"></i> AI-powered parish service portal</span>
            <h1>TUGON</h1>
            <p>Parish requests, sacramental records, reservations, and announcements in one secure Catholic church management platform.</p>
            <div class="auth-copy-list" aria-label="Platform features">
                <span><i class="fas fa-file-signature"></i> Requests</span>
                <span><i class="fas fa-book-bible"></i> Records</span>
                <span><i class="fas fa-calendar-check"></i> Reservations</span>
            </div>
        </aside>

        <section class="auth-glass-card auth-login-card" aria-label="Login form">
            <a href="../index.php" class="auth-brand-mark" aria-label="Back to TUGON homepage">
                <?php if ($has_logo): ?>
                    <img src="../assets/img/san-lorenzo-logo.png" alt="San Lorenzo Ruiz logo">
                <?php else: ?>
                    <i class="fas fa-church"></i>
                <?php endif; ?>
            </a>
            <div class="auth-card-header">
                <span class="auth-eyebrow"><i class="fas fa-cross"></i> San Lorenzo Ruiz Catholic Church</span>
                <h2>Welcome Back</h2>
                <p>Sign in to continue managing parish services through TUGON.</p>
            </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show auth-message" role="alert">
                                <i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($notice): ?>
                            <div class="alert alert-success alert-dismissible fade show auth-message" role="alert">
                                <i class="fas fa-check-circle"></i> <?php echo e($notice); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($status_error): ?>
                            <div class="alert alert-danger alert-dismissible fade show auth-message" role="alert">
                                <i class="fas fa-circle-exclamation"></i> <?php echo e($status_error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($status_notice): ?>
                            <div class="alert alert-info alert-dismissible fade show auth-message" role="status">
                                <i class="fas fa-circle-info"></i> <?php echo e($status_notice); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

            <form method="POST" action="" class="auth-form">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="form_action" value="login">
                            <div class="premium-pill" style="justify-content:center;">
                                <i class="fas fa-shield-halved"></i> Secure parish authentication
                            </div>
                            <div class="auth-field">
                                <label for="email" class="form-label">Email Address</label>
                                <div class="auth-input-wrap">
                                    <i class="fas fa-envelope"></i>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo $email_input; ?>" autocomplete="email" placeholder="name@gmail.com" required autofocus>
                                </div>
                            </div>

                            <div class="auth-field">
                                <label for="password" class="form-label">Password</label>
                                <div class="auth-input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                                    <button type="button" class="auth-password-toggle" data-toggle-password="password" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="auth-options">
                                <label class="auth-check" for="remember">
                                    <input type="checkbox" id="remember" name="remember">
                                    <span>Remember me</span>
                                </label>
                                <a href="forgot-password.php" class="auth-link">Forgot Password?</a>
                            </div>

                            <button type="submit" class="auth-submit" name="login">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </button>
                        </form>

                        <div class="auth-verification-actions">
                            <button type="button" class="auth-social-btn" id="checkStatusToggle"><i class="fas fa-envelope-circle-check"></i> Check Status</button>
                            <button type="button" class="auth-social-btn"><i class="fas fa-key"></i> Send OTP</button>
                            <a href="../index.php" class="auth-social-btn"><i class="fas fa-arrow-left"></i> Back to Home</a>
                        </div>

                        <form method="POST" action="" class="auth-form" id="checkStatusForm" style="<?php echo ($status_error || $status_notice) ? '' : 'display:none;'; ?> margin-top: 14px;">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="form_action" value="check_status">
                            <div class="auth-field">
                                <label for="status_email" class="form-label">Check Registration Status</label>
                                <div class="auth-input-wrap">
                                    <i class="fas fa-envelope-circle-check"></i>
                                    <input type="email" class="form-control" id="status_email" name="status_email" value="<?php echo $status_email_input; ?>" autocomplete="email" placeholder="Enter your registered email">
                                </div>
                            </div>
                            <button type="submit" class="auth-submit">
                                <i class="fas fa-magnifying-glass"></i> Check Account
                            </button>
                        </form>

                        <form method="POST" action="" class="auth-form" style="margin-top: 14px;">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="form_action" value="resend_verification">
                            <div class="auth-field">
                                <label for="resend_email" class="form-label">Resend Gmail Verification</label>
                                <div class="auth-input-wrap">
                                    <i class="fas fa-paper-plane"></i>
                                    <input type="email" class="form-control" id="resend_email" name="resend_email" placeholder="Enter your registered Gmail">
                                </div>
                            </div>
                            <button type="submit" class="auth-social-btn w-100">
                                <i class="fas fa-envelope-circle-check"></i> Resend Verification Email
                            </button>
                        </form>

                        

                        <p class="auth-switch">
                            Don't have an account? <a href="register.php">Register here</a>
                        </p>

                        
        </section>
    </main>

    <script>
        document.querySelectorAll('[data-toggle-password]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const target = document.getElementById(toggle.dataset.togglePassword);
                const icon = toggle.querySelector('i');
                const shouldShow = target.type === 'password';
                target.type = shouldShow ? 'text' : 'password';
                icon.classList.toggle('fa-eye', !shouldShow);
                icon.classList.toggle('fa-eye-slash', shouldShow);
                toggle.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
            });
        });

        document.querySelectorAll('.auth-social-btn').forEach((button) => {
            button.addEventListener('click', () => {
                button.blur();
            });
        });

        const checkStatusToggle = document.getElementById('checkStatusToggle');
        const checkStatusForm = document.getElementById('checkStatusForm');
        if (checkStatusToggle && checkStatusForm) {
            checkStatusToggle.addEventListener('click', () => {
                const isHidden = checkStatusForm.style.display === 'none';
                checkStatusForm.style.display = isHidden ? '' : 'none';
                if (isHidden) {
                    const input = document.getElementById('status_email');
                    if (input) {
                        input.focus();
                    }
                }
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
