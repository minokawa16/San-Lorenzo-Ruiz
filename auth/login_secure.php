<?php
/**
 * Secure Login Page - IMPROVED VERSION
 * Uses prepared statements, rate limiting, account lockout, and enhanced security
 */

// Include security components
include '../config/security.php';
include '../includes/Security.php';
include '../includes/Logger.php';
include '../includes/Pagination.php';
include '../includes/session.php';
include '../database/config.php';
include_once '../includes/helpers.php';
ensureUserVerificationSchema($conn);

// Initialize security
$logger = new Logger();
$security = new Security();
$security->setSecurityHeaders();

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: " . (isAdmin() ? '../admin/dashboard.php' : '../users/dashboard.php'));
    exit;
}

// Initialize variables
$error = '';
$email_input = '';
$remember_me = false;

// Process POST request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Verify CSRF token
        $csrf_token = $_POST[CSRF_TOKEN_NAME] ?? '';
        if (!$security->verifyCSRFToken($csrf_token)) {
            throw new Exception('CSRF token invalid or expired. Please try again.');
        }

        // Get and validate inputs
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);

        $email_input = htmlspecialchars($email);

        // Validate email
        if (empty($email)) {
            throw new Exception('Email is required');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        // Validate password
        if (empty($password)) {
            throw new Exception('Password is required');
        }

        // Check login attempts
        $attempts = $security->checkLoginAttempts($email, $conn);
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            throw new Exception('Too many failed login attempts. Please try again in ' . LOGIN_LOCKOUT_DURATION/60 . ' minutes.');
        }

        // Prepare SQL query with prepared statement
        $sql = "SELECT id, fullname, email, password, role, status, rejection_reason, email_verified_at, login_otp_enabled, account_locked_until 
                FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database error: ' . $conn->error);
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        // Check if user exists
        if ($result->num_rows === 0) {
            $security->logLoginAttempt($email, 'failed', $conn, 'User not found');
            throw new Exception('Invalid email or password');
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        // Check if account is locked
        if ($security->isAccountLocked($user['id'], $conn)) {
            throw new Exception('Your account is temporarily locked due to multiple failed login attempts.');
        }

        // Check if account is active
        if ($user['status'] === 'pending_verification') {
            $security->logLoginAttempt($email, 'failed', $conn, 'Account pending verification');
            throw new Exception('Your registration is currently under review by the parish administrator. Please wait for approval before logging in.');
        }

        if ($user['status'] === 'rejected') {
            $security->logLoginAttempt($email, 'failed', $conn, 'Registration rejected');
            $reason = trim((string) ($user['rejection_reason'] ?? ''));
            throw new Exception('Your registration was not approved by the parish administrator.' . ($reason !== '' ? ' Reason: ' . $reason : ''));
        }

        if (empty($user['email_verified_at'])) {
            $security->logLoginAttempt($email, 'failed', $conn, 'Email not verified');
            throw new Exception('Please verify your Gmail account before logging in.');
        }

        if ($user['status'] !== 'active') {
            $security->logLoginAttempt($email, 'failed', $conn, 'Account inactive');
            throw new Exception('Your account is inactive. Please contact the administrator.');
        }

        // Verify password
        if (!$security->verifyPassword($password, $user['password'])) {
            $security->logLoginAttempt($email, 'failed', $conn, 'Invalid password');
            throw new Exception('Invalid email or password');
        }

        // Password verified - log successful attempt
        $security->logLoginAttempt($email, 'success', $conn);

        if (intval($user['login_otp_enabled']) === 1) {
            sendOtpEmail($conn, $user['id'], $user['email'], 'login');
            $_SESSION['pending_otp_user_id'] = $user['id'];
            $_SESSION['pending_otp_email'] = $user['email'];
            header('Location: verify-otp.php?purpose=login');
            exit;
        }

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();

        // Regenerate session ID (prevent fixation attacks)
        $security->regenerateSessionId();

        // Set remember-me cookie (optional)
        if ($remember_me) {
            $remember_token = $security->generateToken();
            $remember_expiry = time() + (30 * 24 * 60 * 60); // 30 days

            // Store token in database
            $sql = "INSERT INTO user_preferences (user_id, language) VALUES (?, 'en') 
                    ON DUPLICATE KEY UPDATE language='en'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $stmt->close();

            // Set secure cookie
            $security->setSecureCookie('remember_me', $remember_token, $remember_expiry);
        }

        // Update last login
        $ip = $security->getClientIp();
        $sql = "UPDATE users SET last_login = NOW(), last_login_ip = ?, failed_login_attempts = 0 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $ip, $user['id']);
        $stmt->execute();
        $stmt->close();

        // Log successful login
        $logger->info('User logged in successfully', [
            'user_id' => $user['id'],
            'email' => $email,
            'role' => $user['role'],
            'ip' => $ip
        ]);

        // Redirect to appropriate dashboard
        $redirect_url = $user['role'] === 'admin' ? '../admin/dashboard.php' : '../users/dashboard.php';
        header("Location: $redirect_url");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
        $logger->warning('Login attempt failed', ['email' => $email_input, 'error' => $error]);
    }
}

// Generate CSRF token for form
$csrf_token = $security->generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login - San Lorenzo Ruiz Mission Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a3a52;
            --secondary: #d4af37;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary) 0%, #2d5a7b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
            padding: 40px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .form-control {
            border-radius: 5px;
            border: 1px solid #ddd;
            padding: 10px 15px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .form-control:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 0.2rem rgba(212, 175, 55, 0.25);
        }
        
        .btn-login {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            width: 100%;
            margin-top: 10px;
            transition: background 0.3s;
        }
        
        .btn-login:hover {
            background: #0d2438;
            color: white;
        }
        
        .alert {
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .form-check {
            margin-bottom: 15px;
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 15px;
        }
        
        .forgot-password a {
            color: var(--secondary);
            text-decoration: none;
            font-size: 13px;
        }
        
        .forgot-password a:hover {
            text-decoration: underline;
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        
        .register-link p {
            font-size: 13px;
            color: #666;
        }
        
        .register-link a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>">
</head>
<body>
    <div class="container">
        <div class="login-container">
            <!-- Header -->
            <div class="login-header">
                <h1><i class="fas fa-church"></i></h1>
                <h1>Parish System</h1>
                <p>Secure Authentication</p>
            </div>

            <!-- Error Alert -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" novalidate>
                <!-- CSRF Token -->
                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <!-- Email Field -->
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo $email_input; ?>" placeholder="your@email.com" 
                               required autofocus>
                    </div>
                </div>

                <!-- Password Field -->
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter your password" required>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                    <label class="form-check-label" for="remember_me">
                        Remember me for 30 days
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>

                <!-- Forgot Password Link -->
                <div class="forgot-password">
                    <a href="forgot-password.php"><i class="fas fa-question-circle"></i> Forgot Password?</a>
                </div>
            </form>

            <!-- Register Link -->
            <div class="register-link">
                <p>Don't have an account?</p>
                <a href="register.php"><i class="fas fa-user-plus"></i> Create New Account</a>
            </div>

            <!-- Security Notice -->
            <div class="alert alert-info alert-sm mt-3" style="font-size: 12px;">
                <i class="fas fa-shield-alt"></i> This connection is secure and encrypted.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }

            if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
        });
    </script>
</body>
</html>
