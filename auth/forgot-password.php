<?php
/**
 * SMS Forgot Password
 * Sends a TextBee OTP to the registered mobile number before password reset.
 */
require_once '../includes/session.php';

include '../database/config.php';
include '../includes/helpers.php';

ensureEmailNotificationSchema($conn);

$message = '';
$error = csrfFailureMessage();
$step = $_GET['step'] ?? ($_SESSION['password_reset_step'] ?? 'request');
$identifier_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'request_otp') {
        $identifier = trim($_POST['phone_number'] ?? '');
        $identifier_input = e($identifier);
        $normalized = normalizeAuthenticationIdentifier($identifier);
        $user = $normalized['valid'] ? findUserByAuthenticationIdentifier($conn, $identifier) : null;
        $transactionId = bin2hex(random_bytes(32));
        if ($user && ($user['status'] ?? '') === 'active') {
            $sent = createOtpTransaction($conn, (int) $user['id'], 'password_reset', $normalized['type']);
            if (!empty($sent['ok'])) {
                $transactionId = $sent['transaction_id'];
                $_SESSION['password_reset_user_id'] = (int) $user['id'];
                $_SESSION['password_reset_delivery_type'] = $normalized['type'];
                recordPasswordRecoveryEvent($conn, (int) $user['id'], 'recovery_requested', $normalized['type']);
                createAuditLog($conn, (int) $user['id'], 'REQUEST_PASSWORD_RESET_OTP', 'users', (int) $user['id']);
            }
        }
        $_SESSION['password_reset_transaction'] = $transactionId;
        $_SESSION['password_reset_step'] = 'verify';
        unset($_SESSION['password_reset_verified_transaction']);
        $message = 'If an eligible account matches the information provided, a verification code has been sent.';
        $step = 'verify';
    } elseif ($action === 'resend_otp') {
        $userId = (int) ($_SESSION['password_reset_user_id'] ?? 0);
        $deliveryType = (string) ($_SESSION['password_reset_delivery_type'] ?? 'mobile');
        $transactionId = (string) ($_SESSION['password_reset_transaction'] ?? '');

        if ($userId <= 0 && preg_match('/^[a-f0-9]{64}$/', $transactionId)) {
            $prevTx = otpTransactionByPublicId($conn, $transactionId);
            if ($prevTx && !empty($prevTx['user_id'])) {
                $userId = (int) $prevTx['user_id'];
                $deliveryType = $prevTx['delivery_method'] ?? 'mobile';
            }
        }

        if ($userId > 0) {
            $sent = createOtpTransaction($conn, $userId, 'password_reset', $deliveryType);
            if (!empty($sent['ok'])) {
                $_SESSION['password_reset_transaction'] = $sent['transaction_id'];
                $_SESSION['password_reset_user_id'] = $userId;
                $_SESSION['password_reset_delivery_type'] = $deliveryType;
                recordPasswordRecoveryEvent($conn, $userId, 'recovery_requested', $deliveryType);
                createAuditLog($conn, $userId, 'RESEND_PASSWORD_RESET_OTP', 'users', $userId);
                $message = 'A new 6-digit verification code has been sent.';
            } else {
                $error = $sent['error'] ?? 'Unable to send a new code. Please try again.';
            }
        } else {
            $message = 'If an eligible account matches the information provided, a new verification code has been sent.';
        }
        $step = 'verify';
    } elseif ($action === 'verify_otp') {
        $transactionId = (string) ($_SESSION['password_reset_transaction'] ?? '');
        $otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');

        if (!preg_match('/^[a-f0-9]{64}$/', $transactionId)) {
            $error = 'Your password reset session expired. Please request a new OTP.';
            $step = 'request';
        } elseif (strlen($otp) !== 6) {
            $error = 'Please enter the 6-digit OTP.';
            $step = 'verify';
        } else {
            $verified = verifyOtpTransaction($conn, $transactionId, $otp, 'password_reset', false);
            if ($verified['ok']) {
                $user_id = (int) $verified['transaction']['user_id'];
                $_SESSION['password_reset_verified_transaction'] = $transactionId;
                $_SESSION['password_reset_step'] = 'reset';
                recordPasswordRecoveryEvent($conn, $user_id, 'recovery_otp_verified', $verified['transaction']['delivery_method']);
                createAuditLog($conn, $user_id, 'VERIFY_PASSWORD_RESET_OTP', 'users', $user_id);
                $message = 'OTP verified. You may now create a new password.';
                $step = 'reset';
            } else {
                $error = 'The verification code is invalid or expired.';
                $step = 'verify';
            }
        }
    } elseif ($action === 'reset_password') {
        $transactionId = (string) ($_SESSION['password_reset_verified_transaction'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $verifiedTransaction = preg_match('/^[a-f0-9]{64}$/', $transactionId)
            ? otpTransactionByPublicId($conn, $transactionId)
            : null;
        $resetAuthorized = $verifiedTransaction
            && $verifiedTransaction['purpose'] === 'password_reset'
            && $verifiedTransaction['verified_at']
            && !$verifiedTransaction['consumed_at']
            && !$verifiedTransaction['invalidated_at'];
        if (!$resetAuthorized) {
            $error = 'Your password reset session expired. Please request a new OTP.';
            $step = 'request';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
            $step = 'reset';
        } elseif (!isValidPassword($password)) {
            $error = passwordRequirementsMessage();
            $step = 'reset';
        } else {
            $changed = resetPasswordUsingVerifiedTransaction($conn, $transactionId, $password);
            if (!empty($changed['ok'])) {
                $user_id = (int) $changed['user_id'];
                recordPasswordRecoveryEvent($conn, $user_id, 'password_reset_completed', $changed['delivery_method']);
                createAuditLog($conn, $user_id, 'RESET_PASSWORD_OTP', 'users', $user_id);
                unset($_SESSION['password_reset_transaction'], $_SESSION['password_reset_verified_transaction'], $_SESSION['password_reset_step']);
                $message = 'Password successfully changed. You may now log in.';
                $step = 'success';
            } else {
                $error = $changed['error'] ?? 'Unable to update your password. Please try again.';
                $step = 'reset';
            }
        }
    }
}

$logo_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'san-lorenzo-logo.png';
$has_logo = is_file($logo_file);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | San Lorenzo Ruiz Mission Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
    <style>
        :root {
            --reset-ocean: #08739A;
            --reset-link: #149BB5;
            --reset-teal: #2AA6AF;
            --reset-aqua: #91C2B9;
            --reset-border: #D2D8D3;
            --reset-text: #203238;
            --reset-muted: #52686B;
        }

        body.auth-cinematic-page {
            background:
                linear-gradient(90deg, rgba(8, 115, 154, 0.42), rgba(32, 50, 56, 0.18) 45%, rgba(32, 50, 56, 0.58)),
                linear-gradient(180deg, rgba(8, 115, 154, 0.14), rgba(32, 50, 56, 0.62)),
                url("../church%20image.png") center center / cover no-repeat fixed !important;
            color: var(--reset-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
        }

        body.auth-cinematic-page::before {
            background-image: url("../church%20image.png") !important;
            background-size: cover !important;
            background-position: center center !important;
            filter: saturate(1.06) contrast(1.03) brightness(0.78) !important;
        }

        body.auth-cinematic-page::after {
            background:
                radial-gradient(circle at 18% 12%, rgba(145, 194, 185, 0.24), transparent 28%),
                linear-gradient(90deg, rgba(8, 115, 154, 0.42), rgba(32, 50, 56, 0.12) 45%, rgba(32, 50, 56, 0.6)) !important;
        }

        .auth-ambient {
            opacity: 0 !important;
        }

        .auth-screen {
            width: min(100%, 720px) !important;
            padding: 32px 16px !important;
        }

        .auth-glass-card {
            width: min(100%, 560px) !important;
            background:
                radial-gradient(circle at 96% 8%, rgba(145, 194, 185, 0.22), transparent 28%),
                #FFFFFF !important;
            border: 1px solid rgba(255, 255, 255, 0.72) !important;
            border-radius: 18px !important;
            color: var(--reset-text) !important;
            box-shadow: 0 28px 70px rgba(8, 115, 154, 0.22) !important;
            backdrop-filter: none !important;
            padding: clamp(34px, 5vw, 52px) !important;
        }

        .auth-brand-mark {
            background: #FFFFFF !important;
            border: 1px solid var(--reset-border) !important;
            box-shadow: 0 14px 34px rgba(8, 115, 154, 0.16) !important;
        }

        .auth-eyebrow {
            background: rgba(8, 115, 154, 0.12) !important;
            border: 1px solid rgba(8, 115, 154, 0.24) !important;
            font-size: 15px !important;
            line-height: 1.5 !important;
        }

        .auth-message.alert-danger {
            border-color: rgba(180, 35, 24, 0.28) !important;
        }

        .auth-message.alert-success {
            border-color: rgba(20, 155, 181, 0.28) !important;
        }

        /* Warm cream/gold password reset restoration. */
        :root {
            --reset-ocean: #1C1B18;
            --reset-link: #B88A22;
            --reset-teal: #D4A94E;
            --reset-aqua: #F6DF9F;
            --reset-border: #DFCFAA;
            --reset-text: #1C1B18;
            --reset-muted: #6F675A;
        }

        body.auth-cinematic-page {
            background:
                linear-gradient(90deg, rgba(15, 10, 6, 0.62) 0%, rgba(15, 10, 6, 0.24) 44%, rgba(8, 11, 18, 0.78) 100%),
                linear-gradient(180deg, rgba(0, 0, 0, 0.22), rgba(0, 0, 0, 0.62)),
                url("../church%20image.png") center center / cover no-repeat fixed !important;
        }

        body.auth-cinematic-page::before {
            background-image: url("../church%20image.png") !important;
            filter: sepia(0.22) saturate(1.18) contrast(1.05) brightness(0.82) !important;
        }

        body.auth-cinematic-page::after {
            background:
                radial-gradient(circle at 39% 30%, rgba(255, 214, 126, 0.22), transparent 25%),
                radial-gradient(circle at 50% 70%, rgba(255, 184, 64, 0.16), transparent 24%),
                linear-gradient(90deg, rgba(15, 10, 6, 0.62) 0%, rgba(15, 10, 6, 0.24) 44%, rgba(8, 11, 18, 0.78) 100%) !important;
        }

        .auth-glass-card {
            background:
                radial-gradient(circle at 96% 8%, rgba(246, 223, 159, 0.28), transparent 28%),
                #FFF8EB !important;
            border-color: rgba(255, 248, 235, 0.68) !important;
            color: var(--reset-text) !important;
            box-shadow: 0 34px 90px rgba(0, 0, 0, 0.32) !important;
        }

        .auth-brand-mark {
            background: #FFF8EB !important;
            border-color: var(--reset-border) !important;
            box-shadow: 0 14px 34px rgba(28, 27, 24, 0.14) !important;
        }

        .auth-eyebrow {
            background: rgba(212, 169, 78, 0.16) !important;
            border-color: rgba(212, 169, 78, 0.34) !important;
            color: var(--reset-text) !important;
        }

        .auth-eyebrow i,
        .auth-brand-mark i,
        .auth-input-wrap i {
            color: var(--reset-link) !important;
        }

        .auth-input-wrap {
            background: #FFFFFF !important;
            border-color: var(--reset-border) !important;
        }

        .auth-input-wrap:focus-within {
            border-color: var(--reset-teal) !important;
            box-shadow: 0 0 0 4px rgba(212, 169, 78, 0.18) !important;
        }

        .auth-submit {
            background: linear-gradient(135deg, #D4A94E, #B88A22) !important;
            border-color: #B88A22 !important;
            color: var(--reset-text) !important;
            box-shadow: 0 16px 34px rgba(212, 169, 78, 0.24) !important;
        }

        .auth-submit *,
        .auth-submit i {
            color: var(--reset-text) !important;
        }

        .auth-submit:hover,
        .auth-submit:focus-visible {
            background: linear-gradient(135deg, #B88A22, #9B741A) !important;
            border-color: #9B741A !important;
        }

        .auth-switch a {
            color: var(--reset-link) !important;
        }

        .auth-message {
            background: #FFFFFF !important;
            border-color: var(--reset-border) !important;
            color: var(--reset-text) !important;
        }

        /* --- GLOBAL INPUT & PREFIX ICON STANDARDIZATION --- */
        .auth-input-wrap {
            position: relative !important;
            display: block !important;
            width: 100% !important;
            background: transparent !important;
            border: none !important;
            min-height: 48px !important;
            box-shadow: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .auth-input-wrap > i:first-child,
        .auth-input-wrap i {
            position: absolute !important;
            left: 14px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 20px !important;
            height: 20px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            pointer-events: none !important;
            z-index: 5 !important;
            font-size: 15px !important;
            margin: 0 !important;
        }

        .auth-input-wrap .form-control {
            display: block !important;
            width: 100% !important;
            min-height: 48px !important;
            padding: 10px 16px 10px 44px !important;
            border-radius: 8px !important;
            border: 1px solid var(--reset-border) !important;
            background: #FFFFFF !important;
            color: var(--reset-text) !important;
            -webkit-text-fill-color: var(--reset-text) !important;
            font-size: 16px !important;
            box-sizing: border-box !important;
        }

        .auth-input-wrap .form-control::placeholder {
            color: #667575 !important;
            -webkit-text-fill-color: #667575 !important;
            opacity: 1 !important;
        }

        .auth-input-wrap:focus-within .form-control {
            border-color: var(--reset-link) !important;
            box-shadow: 0 0 0 4px rgba(212, 169, 78, 0.18) !important;
        }

        .auth-password-toggle {
            position: absolute !important;
            right: 8px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 36px !important;
            height: 36px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: transparent !important;
            border: none !important;
            cursor: pointer !important;
            z-index: 6 !important;
        }

        .auth-input-wrap:has(.auth-password-toggle) .form-control,
        .auth-input-wrap:has(button) .form-control {
            padding-right: 48px !important;
        }
    </style>

    <link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>">
    <link rel="stylesheet" href="../assets/css/auth-mobile.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/auth-mobile.css'); ?>">
</head>
<body class="auth-cinematic-page">
    <div class="auth-ambient" aria-hidden="true"></div>
    <main class="auth-screen">
        <section class="auth-glass-card" aria-label="Password recovery form">
            <a href="../index.php" class="auth-brand-mark" aria-label="Back to TUGON homepage">
                <?php if ($has_logo): ?>
                    <img src="../assets/img/san-lorenzo-logo.png" alt="San Lorenzo Ruiz logo">
                <?php else: ?>
                    <i class="fas fa-church"></i>
                <?php endif; ?>
            </a>
            <div class="auth-card-header">
                <span class="auth-eyebrow"><i class="fas fa-key"></i> Password Reset</span>
                <h1>Forgot Password?</h1>
                <p>Use your registered Gmail address or mobile number to receive a secure one-time password.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger auth-message" role="alert"><i class="fas fa-triangle-exclamation"></i> <?php echo e($error); ?></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="alert alert-success auth-message" role="status"><i class="fas fa-circle-check"></i> <?php echo e($message); ?></div>
            <?php endif; ?>
            <?php if ($step === 'verify' && !empty($_SESSION['last_dev_otp']) && (!defined('APP_ENVIRONMENT') || APP_ENVIRONMENT !== 'production')): ?>
                <div class="alert alert-info auth-message" role="status" style="background: rgba(200, 155, 60, 0.15); border-color: rgba(200, 155, 60, 0.4); color: #805c10;">
                    <i class="fas fa-info-circle"></i> Development Code: Your OTP is <strong><?php echo e($_SESSION['last_dev_otp']); ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($step === 'verify'): ?>
                <form method="POST" class="auth-form" id="verifyOtpForm">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="verify_otp">
                    <div class="auth-field">
                        <label class="form-label" for="otp">Enter 6-Digit OTP</label>
                        <div class="auth-input-wrap">
                            <i class="fas fa-shield-halved"></i>
                            <input class="form-control" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="483921" required autofocus>
                        </div>
                    </div>
                    <button class="auth-submit" type="submit"><i class="fas fa-check"></i> Verify OTP</button>
                </form>

                <div class="auth-switch mt-3 pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <form method="POST" class="d-inline m-0 p-0" id="resendOtpForm">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="resend_otp">
                        <span style="font-size: 0.9rem; color: var(--reset-muted);">Didn't receive code?</span>
                        <button type="submit" class="btn btn-link p-0 ms-1 text-decoration-none fw-semibold" id="resendOtpBtn" style="color: var(--reset-link); font-size: 0.92rem; vertical-align: baseline;">
                            <i class="fas fa-rotate-right me-1"></i> Resend OTP
                        </button>
                    </form>
                    <a href="forgot-password.php?step=request" class="text-decoration-none" style="font-size: 0.88rem; color: var(--reset-muted);">
                        <i class="fas fa-pen-to-square me-1"></i> Change number/email
                    </a>
                </div>
            <?php elseif ($step === 'reset'): ?>
                <form method="POST" class="auth-form">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <div class="auth-field">
                        <label class="form-label" for="password">New Password</label>
                        <div class="auth-input-wrap">
                            <i class="fas fa-lock"></i>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" required autofocus>
                        </div>
                    </div>
                    <div class="auth-field">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <div class="auth-input-wrap">
                            <i class="fas fa-lock"></i>
                            <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" required>
                        </div>
                    </div>
                    <button class="auth-submit" type="submit"><i class="fas fa-key"></i> Change Password</button>
                </form>
            <?php elseif ($step === 'success'): ?>
                <a class="auth-submit text-center text-decoration-none" href="login.php"><i class="fas fa-sign-in-alt"></i> Back to Login</a>
            <?php else: ?>
                <form method="POST" class="auth-form">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="request_otp">
                    <div class="auth-field">
                        <label class="form-label" for="phone_number">Registered Email Address or Mobile Number</label>
                        <div class="auth-input-wrap">
                            <i class="fas fa-phone"></i>
                            <input class="form-control" id="phone_number" name="phone_number" value="<?php echo $identifier_input; ?>" inputmode="text" maxlength="150" placeholder="name@gmail.com or 09XXXXXXXXX" required autofocus>
                        </div>
                    </div>
                    <button class="auth-submit" type="submit"><i class="fas fa-paper-plane"></i> Send OTP</button>
                </form>
            <?php endif; ?>

            <p class="auth-switch">Remembered your password? <a href="login.php">Back to Login</a></p>
        </section>
    </main>
</body>
</html>
