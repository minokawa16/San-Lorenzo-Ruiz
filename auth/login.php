<?php
/**
 * Login Page
 * AI-Powered Parish Request and Sacramental Records Management System
 * Handles user authentication with proper password verification and security
 */

require_once '../includes/session.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

$hasExplicitAuthError = (isset($_GET['session']) && $_GET['session'] === 'expired')
    || (isset($_GET['error']) && $_GET['error'] === 'forbidden');

if ($hasExplicitAuthError) {
    clearAuthenticationSession();
}

// If already logged in and not arriving due to an auth error, redirect to appropriate dashboard
if (isLoggedIn()) {
    header('Location: ' . getUserDashboardURL(), true, 302);
    exit;
}

$error = '';
$csrf_error = csrfFailureMessage();
$notice = isset($_GET['registered']) ? 'Your registration is currently under review by the parish administrator. Please wait for approval before logging in.' : '';
$status_notice = '';
$status_error = '';
$identifier_input = '';
$status_email_input = '';

if ($csrf_error !== '') {
    $error = $csrf_error;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['session']) && $_GET['session'] === 'expired') {
        $error = 'Your session has expired. Please log in again to continue.';
        queueActionNotification('Session expired. Please log in again.', 'warning');
    }
    if (isset($_GET['error']) && $_GET['error'] === 'forbidden') {
        $error = 'Access denied. Please sign in with an authorized parish account.';
        queueActionNotification('Access denied. Please log in with an authorized account.', 'error');
    }
    if (isset($_GET['notice']) && $_GET['notice'] === 'login_required') {
        $status_notice = 'Please sign in to access your parish account.';
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    $form_action = $_POST['form_action'] ?? 'login';

    if ($form_action === 'check_status') {
        $status_email = trim($_POST['status_email'] ?? '');
        $status_email_input = htmlspecialchars($status_email);
        $status_notice = 'If the information matches a registration, its current status and any required next step will be available after secure identity verification. Contact the parish office if you need assistance.';
    } else {
        $identifier = trim((string) ($_POST['email'] ?? ($_POST['identifier'] ?? ($_POST['phone_number'] ?? ''))));
        $password = (string) ($_POST['password'] ?? '');
        $identifier_input = htmlspecialchars($identifier);

        if ($identifier === '' || $password === '') {
            $error = 'The credentials provided are invalid.';
        } else {
            $authentication = beginPasswordAuthentication($conn, $identifier, $password);
            if (empty($authentication['ok'])) {
                createAuditLog($conn, null, 'LOGIN_FAILURE', 'users', null, null, ['identifier_hash'=>hash('sha256',strtolower($identifier)),'reason'=>'authentication_failed']);
                $error = $authentication['error'] ?? 'The credentials provided are invalid.';
            } else {
                createAuditLog($conn, (int) $_SESSION['user_id'], 'LOGIN', 'users', (int) $_SESSION['user_id']);
                redirectAfterLogin();
            }
        }
    }
}
$action_notifications = function_exists('consumeActionNotifications') ? consumeActionNotifications() : [];

$css_version = file_exists(__DIR__ . '/../assets/css/login-slr.css') ? filemtime(__DIR__ . '/../assets/css/login-slr.css') : time();
$logo_version = file_exists(__DIR__ . '/../assets/img/slr_logo.png') ? filemtime(__DIR__ . '/../assets/img/slr_logo.png') : time();
$bg_version = file_exists(__DIR__ . '/../assets/img/login-church-bg.webp') ? filemtime(__DIR__ . '/../assets/img/login-church-bg.webp') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to San Lorenzo Ruiz Mission Station Parish Management System. Access sacramental records, services, and announcements.">
    <title>Login | San Lorenzo Ruiz Mission Station</title>
    
    <!-- Preload Desktop Church Background Image -->
    <link rel="preload" as="image" href="../assets/img/login-church-bg.webp?v=<?php echo $bg_version; ?>" type="image/webp">
    
    <!-- Local Fonts and Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/login-slr.css?v=<?php echo $css_version; ?>">

    <script>
        try {
            sessionStorage.removeItem('admin_sidebar_state');
            localStorage.removeItem('sidebar_state');
            localStorage.removeItem('adminSidebarCollapsed');
        } catch (e) {}
    </script>
</head>
<body class="auth-slr-redesign">

    <!-- Fallback Bokeh Background & Pre-blurred Church Photo -->
    <div class="slr-bg-backdrop" aria-hidden="true"></div>
    <div class="slr-bg-image" aria-hidden="true"></div>
    <div class="slr-bg-overlay" aria-hidden="true"></div>

    <!-- Centered Split Container -->
    <main class="slr-split-container">
        
        <!-- Left Panel: Dark Arch Brand Panel -->
        <aside class="slr-arch-panel" aria-label="San Lorenzo Ruiz Mission Station">
            
            <!-- Botanical Fern Line-Art Pattern (Lower Corners) -->
            <svg class="slr-arch-botanical" viewBox="0 0 400 180" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" preserveAspectRatio="none">
                <!-- Left corner fern fronds -->
                <path d="M-10 180 C30 140 60 100 80 50" stroke="#D4B25E" stroke-width="1.4" stroke-linecap="round"/>
                <path d="M15 155 C35 150 45 140 40 135 C30 135 20 145 15 155 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M30 135 C52 130 62 118 55 114 C44 115 35 125 30 135 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M45 115 C70 108 78 95 70 92 C60 93 50 104 45 115 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M60 92 C85 82 92 68 83 66 C73 68 64 80 60 92 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M72 70 C92 58 96 46 88 44 C80 46 73 58 72 70 Z" stroke="#D4B25E" stroke-width="0.9"/>
                
                <path d="M20 180 C60 160 110 145 150 125" stroke="#D4B25E" stroke-width="1.2" stroke-linecap="round"/>
                <path d="M50 168 C75 162 90 152 82 148 C72 150 58 160 50 168 Z" stroke="#D4B25E" stroke-width="0.8"/>
                <path d="M80 155 C108 148 120 136 112 132 C102 135 88 145 80 155 Z" stroke="#D4B25E" stroke-width="0.8"/>
                <path d="M110 142 C135 132 145 120 137 117 C128 120 118 132 110 142 Z" stroke="#D4B25E" stroke-width="0.8"/>

                <!-- Right corner fern fronds -->
                <path d="M410 180 C370 140 340 100 320 50" stroke="#D4B25E" stroke-width="1.4" stroke-linecap="round"/>
                <path d="M385 155 C365 150 355 140 360 135 C370 135 380 145 385 155 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M370 135 C348 130 338 118 345 114 C356 115 365 125 370 135 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M355 115 C330 108 322 95 330 92 C340 93 350 104 355 115 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M340 92 C315 82 308 68 317 66 C327 68 336 80 340 92 Z" stroke="#D4B25E" stroke-width="0.9"/>
                <path d="M328 70 C308 58 304 46 312 44 C320 46 327 58 328 70 Z" stroke="#D4B25E" stroke-width="0.9"/>

                <path d="M380 180 C340 160 290 145 250 125" stroke="#D4B25E" stroke-width="1.2" stroke-linecap="round"/>
                <path d="M350 168 C325 162 310 152 318 148 C328 150 342 160 350 168 Z" stroke="#D4B25E" stroke-width="0.8"/>
                <path d="M320 155 C292 148 280 136 288 132 C298 135 312 145 320 155 Z" stroke="#D4B25E" stroke-width="0.8"/>
                <path d="M290 142 C265 132 255 120 263 117 C272 120 282 132 290 142 Z" stroke="#D4B25E" stroke-width="0.8"/>
            </svg>

            <div class="slr-arch-content">
                <!-- Double Ring Glowing Logo -->
                <div class="slr-logo-wrap">
                    <picture>
                        <source srcset="../assets/img/slr_logo.webp?v=<?php echo $logo_version; ?>" type="image/webp">
                        <img src="../assets/img/slr_logo.png?v=<?php echo $logo_version; ?>" alt="San Lorenzo Ruiz Mission Station" class="slr-logo-img" width="106" height="106">
                    </picture>
                </div>

                <!-- Mission Station Titles -->
                <h2 class="slr-arch-title">SAN LORENZO RUIZ</h2>
                <div class="slr-arch-subtitle">MISSION STATION</div>

                <!-- Cross Divider -->
                <div class="slr-arch-divider" aria-hidden="true">
                    <span class="divider-line"></span>
                    <span class="divider-cross">✝</span>
                    <span class="divider-line"></span>
                </div>

                <!-- Headline with Gold Accents -->
                <div class="slr-arch-headline">
                    Serving the <span class="gold-text">Parish</span> through <span class="gold-text">Faith, Community,</span> and <span class="gold-text">Technology.</span>
                </div>

                <!-- Supporting Description -->
                <p class="slr-arch-description">
                    A secure digital platform for parish services, sacramental records, requests, and announcements
                </p>

                <!-- Thin Gold Gradient Divider -->
                <div class="slr-arch-gold-line" aria-hidden="true"></div>
            </div>

            <!-- Bottom Pill Badge -->
            <div class="slr-arch-badge">
                <i class="fas fa-church" aria-hidden="true"></i>
                <span>Faithfully serving our parish community.</span>
            </div>
        </aside>

        <!-- Right Card: Frosted Cream Glass -->
        <section class="slr-glass-card" aria-label="Login form">
            
            <!-- Top Cross Flourish Ornament -->
            <div class="slr-card-ornament" aria-hidden="true">
                <svg width="130" height="26" viewBox="0 0 130 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 13 C 24 13, 30 8, 40 13 C 48 17, 54 13, 58 13" stroke="#C9A24B" stroke-width="1.2" stroke-linecap="round"/>
                    <circle cx="10" cy="13" r="1.5" fill="#C9A24B"/>
                    <path d="M28 10 C 32 7, 36 9, 35 12" stroke="#C9A24B" stroke-width="0.8" fill="none"/>
                    
                    <path d="M65 3 L65 23" stroke="#C9A24B" stroke-width="2" stroke-linecap="round"/>
                    <path d="M59 8 L71 8" stroke="#C9A24B" stroke-width="2" stroke-linecap="round"/>
                    
                    <path d="M72 13 C 76 13, 82 17, 90 13 C 100 8, 106 13, 118 13" stroke="#C9A24B" stroke-width="1.2" stroke-linecap="round"/>
                    <circle cx="120" cy="13" r="1.5" fill="#C9A24B"/>
                    <path d="M102 10 C 98 7, 94 9, 95 12" stroke="#C9A24B" stroke-width="0.8" fill="none"/>
                </svg>
            </div>

            <!-- Card Header -->
            <header class="slr-card-header">
                <h1 class="slr-card-title">Welcome Back</h1>
                <p class="slr-card-subtitle">Sign in to access your Parish Management System account.</p>
            </header>

            <!-- Server Notices & Errors -->
            <?php if ($error): ?>
                <div class="slr-alert slr-alert-danger" role="alert">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                    <button type="button" class="slr-alert-close" onclick="this.parentElement.remove()" aria-label="Close error">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="slr-alert slr-alert-success" role="alert">
                    <i class="fas fa-circle-check" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($notice); ?></div>
                    <button type="button" class="slr-alert-close" onclick="this.parentElement.remove()" aria-label="Close notice">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($status_error): ?>
                <div class="slr-alert slr-alert-danger" role="alert">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($status_error); ?></div>
                    <button type="button" class="slr-alert-close" onclick="this.parentElement.remove()" aria-label="Close error">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($status_notice): ?>
                <div class="slr-alert slr-alert-info" role="status">
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($status_notice); ?></div>
                    <button type="button" class="slr-alert-close" onclick="this.parentElement.remove()" aria-label="Close message">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Login Form (Original Action & Method Preserved) -->
            <form method="POST" action="login.php" class="slr-form" id="authLoginForm">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="form_action" value="login">

                <!-- Field 1: Email or Mobile -->
                <div class="slr-field">
                    <label for="email" class="slr-label">Email Address or Mobile Number</label>
                    <div class="slr-input-group">
                        <span class="slr-icon-chip" aria-hidden="true"><i class="fas fa-envelope"></i></span>
                        <input type="text" 
                               class="slr-input" 
                               id="email" 
                               name="email" 
                               value="<?php echo $identifier_input; ?>" 
                               autocomplete="username" 
                               placeholder="name@gmail.com or 09XXXXXXXXX" 
                               required 
                               autofocus>
                    </div>
                </div>

                <!-- Field 2: Password with Eye Toggle -->
                <div class="slr-field">
                    <label for="password" class="slr-label">Password</label>
                    <div class="slr-input-group">
                        <span class="slr-icon-chip" aria-hidden="true"><i class="fas fa-lock"></i></span>
                        <input type="password" 
                               class="slr-input slr-input-password" 
                               id="password" 
                               name="password" 
                               autocomplete="current-password" 
                               placeholder="Enter your password" 
                               required>
                        <button type="button" 
                                class="slr-eye-btn" 
                                id="togglePasswordBtn" 
                                data-toggle-password="password" 
                                aria-label="Show password" 
                                aria-pressed="false" 
                                title="Show password">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <!-- Caps Lock Hint -->
                    <div id="capsLockWarning" class="slr-caps-hint" style="display:none;" aria-live="polite">
                        <i class="fas fa-arrow-up-from-bracket" aria-hidden="true"></i>
                        <span>Caps Lock is ON</span>
                    </div>
                </div>

                <!-- Options Row: Checkbox & Forgot Password -->
                <div class="slr-options-row">
                    <label class="slr-checkbox-label" for="remember">
                        <input type="checkbox" class="slr-checkbox" id="remember" name="remember">
                        <span>Keep me signed in</span>
                    </label>
                    <a href="forgot-password.php" class="slr-forgot-link">Forgot Password?</a>
                </div>

                <!-- Primary Submit Button -->
                <button type="submit" class="slr-btn-submit" name="login" id="loginSubmitBtn">
                    <span class="btn-text">Access Dashboard</span>
                    <i class="fas fa-arrow-right btn-arrow" aria-hidden="true"></i>
                    <span class="spinner-icon" style="display:none;" aria-hidden="true"><i class="fas fa-circle-notch"></i></span>
                </button>
            </form>

            <!-- Links & Verification Section -->
            <div class="slr-nav-section">
                <p class="slr-status-prompt">Need to check an existing request?</p>
                <button type="button" class="slr-btn-status-toggle" id="checkStatusToggle">
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i> Check Request Status →
                </button>

                <!-- Check Request Status Collapsible Form -->
                <div class="slr-status-box" id="checkStatusContainer" style="<?php echo ($status_error || $status_notice) ? '' : 'display:none;'; ?>">
                    <form method="POST" action="" class="slr-form" id="checkStatusForm">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="form_action" value="check_status">
                        <div class="slr-field" style="margin-bottom: 12px; text-align: left;">
                            <label for="status_email" class="slr-label">Check Registration Status</label>
                            <div class="slr-input-group">
                                <span class="slr-icon-chip" aria-hidden="true"><i class="fas fa-envelope-circle-check"></i></span>
                                <input type="text" 
                                       class="slr-input" 
                                       id="status_email" 
                                       name="status_email" 
                                       value="<?php echo $status_email_input; ?>" 
                                       autocomplete="username" 
                                       placeholder="Enter your registered email or mobile number">
                            </div>
                        </div>
                        <button type="submit" class="slr-btn-submit" style="height: 44px; font-size: 14px;">
                            <i class="fas fa-magnifying-glass" aria-hidden="true"></i> Check Account
                        </button>
                    </form>
                </div>

                <!-- Back to Homepage -->
                <a href="../index.php" class="slr-back-home">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Homepage
                </a>

                <!-- New Parishioner Register -->
                <p class="slr-register-prompt">
                    New Parishioner? <a href="register.php" class="slr-register-link">Create an Account →</a>
                </p>

                <!-- Information Protected Note -->
                <div class="slr-security-notice">
                    <i class="fas fa-lock" aria-hidden="true"></i>
                    <span>Your information is securely protected</span>
                </div>
            </div>

            <!-- Corner Shield Emblem (Bottom Right) -->
            <svg class="slr-corner-shield" width="56" height="66" viewBox="0 0 56 66" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <!-- Outer Shield Crest -->
                <path d="M28 2 C40 8 52 7 54 12 C54 36 43 54 28 64 C13 54 2 36 2 12 C4 7 16 8 28 2 Z" stroke="#C9A24B" stroke-width="1.5" fill="none"/>
                <!-- Inner Shield Dashed Accent -->
                <path d="M28 6 C38 11 48 10 49 14 C49 34 40 49 28 58 C16 49 7 34 7 14 C8 10 18 11 28 6 Z" stroke="#C9A24B" stroke-width="0.8" stroke-dasharray="2.5 2" fill="none" opacity="0.65"/>
                <!-- Lock Shackle -->
                <path d="M23 31 V26 C23 23.2 25.2 21 28 21 C30.8 21 33 23.2 33 26 V31" stroke="#C9A24B" stroke-width="1.6" stroke-linecap="round"/>
                <!-- Lock Body -->
                <rect x="20" y="31" width="16" height="13" rx="3" stroke="#C9A24B" stroke-width="1.5" fill="rgba(201, 162, 75, 0.18)"/>
                <!-- Keyhole -->
                <circle cx="28" cy="36" r="1.8" fill="#C9A24B"/>
                <path d="M28 37.5 V40.5" stroke="#C9A24B" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
        </section>
    </main>

    <!-- Client-side Interactive Logic -->
    <script>
        window.parishInitialNotifications = <?php echo json_encode($action_notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // Password Show/Hide Toggle
        const toggleBtn = document.getElementById('togglePasswordBtn');
        const passwordInput = document.getElementById('password');
        if (toggleBtn && passwordInput) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                const icon = toggleBtn.querySelector('i');
                if (icon) {
                    icon.classList.remove(isPassword ? 'fa-eye' : 'fa-eye-slash');
                    icon.classList.add(isPassword ? 'fa-eye-slash' : 'fa-eye');
                }
                const newLabel = isPassword ? 'Hide password' : 'Show password';
                toggleBtn.setAttribute('aria-label', newLabel);
                toggleBtn.setAttribute('title', newLabel);
                toggleBtn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            });
        }

        // Caps Lock Warning
        if (passwordInput) {
            const capsWarning = document.getElementById('capsLockWarning');
            ['keydown', 'keyup'].forEach(eventType => {
                passwordInput.addEventListener(eventType, function(e) {
                    if (e.getModifierState && e.getModifierState('CapsLock')) {
                        if (capsWarning) capsWarning.style.display = 'flex';
                    } else {
                        if (capsWarning) capsWarning.style.display = 'none';
                    }
                });
            });
        }

        // Double Submit Prevention & Loading State
        const loginForm = document.getElementById('authLoginForm');
        const submitBtn = document.getElementById('loginSubmitBtn');
        if (loginForm && submitBtn) {
            loginForm.addEventListener('submit', function(e) {
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return false;
                }
                submitBtn.disabled = true;
                const btnText = submitBtn.querySelector('.btn-text');
                const btnArrow = submitBtn.querySelector('.btn-arrow');
                const spinner = submitBtn.querySelector('.spinner-icon');
                if (btnText) btnText.textContent = 'Signing in...';
                if (btnArrow) btnArrow.style.display = 'none';
                if (spinner) spinner.style.display = 'inline-block';
            });
        }

        // Check Request Status Toggle
        const checkStatusToggle = document.getElementById('checkStatusToggle');
        const checkStatusContainer = document.getElementById('checkStatusContainer');
        if (checkStatusToggle && checkStatusContainer) {
            checkStatusToggle.addEventListener('click', function() {
                const isHidden = checkStatusContainer.style.display === 'none';
                checkStatusContainer.style.display = isHidden ? 'block' : 'none';
                if (isHidden) {
                    const statusInput = document.getElementById('status_email');
                    if (statusInput) statusInput.focus();
                }
            });
        }
    </script>
</body>
</html>
