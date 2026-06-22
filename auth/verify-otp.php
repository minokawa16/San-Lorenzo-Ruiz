<?php
/**
 * OTP Verification Module - Validates one-time passcodes for secure account access.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../database/config.php';
include '../includes/helpers.php';

ensureEmailNotificationSchema($conn);

$purpose = $_GET['purpose'] ?? ($_POST['purpose'] ?? 'registration');
$allowed_purposes = ['registration', 'login'];
if (!in_array($purpose, $allowed_purposes, true)) {
    $purpose = 'registration';
}

$user_id = intval($_GET['user_id'] ?? ($_POST['user_id'] ?? ($_SESSION['pending_otp_user_id'] ?? 0)));
$method = $_GET['method'] ?? ($_POST['method'] ?? 'email');
if (!in_array($method, ['email', 'mobile'], true)) {
    $method = 'email';
}
$contact = trim($_GET['contact'] ?? ($_POST['contact'] ?? ''));
$email = trim($_GET['email'] ?? ($_POST['email'] ?? ($_SESSION['pending_otp_email'] ?? '')));
if ($contact === '') {
    $contact = $email;
}
$otp_recipient = $method === 'mobile' ? $contact : $email;
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $sent = $method === 'mobile'
            ? sendOtpSms($conn, $user_id, $otp_recipient, $purpose)
            : sendOtpEmail($conn, $user_id, $otp_recipient, $purpose);
        $success = $sent['ok']
            ? ($method === 'mobile' ? 'A new OTP was sent to your mobile number.' : 'A new OTP was sent to your Gmail address.')
            : ($sent['error'] ?: 'Unable to send OTP.');
    } else {
        $otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');
        if (strlen($otp) !== 6) {
            $error = 'Please enter the 6-digit OTP.';
        } else {
            $verified = verifyOtpCode($conn, $user_id, $otp_recipient, $purpose, $otp);
            if ($verified['ok']) {
                if ($purpose === 'registration') {
                    if ($method === 'mobile') {
                        $conn->query("UPDATE users SET phone_verified_at = NOW() WHERE id = " . intval($user_id));
                        createAuditLog($conn, $user_id, 'VERIFY_REGISTRATION_MOBILE_OTP', 'users', $user_id);
                    } else {
                        $conn->query("UPDATE users SET email_verified_at = NOW() WHERE id = " . intval($user_id));
                        createAuditLog($conn, $user_id, 'VERIFY_REGISTRATION_EMAIL_OTP', 'users', $user_id);
                    }
                    $success = 'OTP verified. Your registration is now ready for parish administrator review.';
                } else {
                    $stmt = $conn->prepare("SELECT id, fullname, email, role FROM users WHERE id = ? AND email = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('is', $user_id, $email);
                        $stmt->execute();
                        $user = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($user) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['fullname'] = $user['fullname'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_email']);
                            createAuditLog($conn, $user_id, 'VERIFY_LOGIN_OTP', 'users', $user_id);
                            header("Location: " . ($user['role'] === 'admin' ? '../admin/dashboard.php' : '../users/dashboard.php'));
                            exit;
                        }
                    }
                    $error = 'Unable to complete login OTP verification.';
                }
            } else {
                $error = $verified['error'];
            }
        }
    }
}
?>
<?php
$verification_title = $method === 'mobile' ? 'Verify Your Mobile Number' : 'Verify Your Email Address';
$recipient_label = $method === 'mobile' ? 'mobile number' : 'Gmail address';
$masked_recipient = $otp_recipient;
if ($method === 'mobile') {
    $digits = preg_replace('/\D/', '', $otp_recipient);
    $masked_recipient = strlen($digits) >= 7
        ? substr($digits, 0, 2) . str_repeat('*', max(0, strlen($digits) - 6)) . substr($digits, -4)
        : $otp_recipient;
} elseif (strpos($otp_recipient, '@') !== false) {
    [$local, $domain] = explode('@', $otp_recipient, 2);
    $masked_recipient = substr($local, 0, 1) . str_repeat('*', max(4, strlen($local) - 1)) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification | TUGON</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --otp-primary: #2563EB;
            --otp-success: #10B981;
            --otp-error: #EF4444;
            --otp-text: #111827;
            --otp-muted: #64748b;
            --otp-border: #e5e7eb;
            --otp-surface: rgba(255, 255, 255, 0.92);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--otp-text);
            background:
                radial-gradient(circle at 20% 12%, rgba(37, 99, 235, 0.12), transparent 28%),
                radial-gradient(circle at 84% 10%, rgba(16, 185, 129, 0.1), transparent 24%),
                linear-gradient(135deg, #f8fafc 0%, #eef4ff 48%, #f9fafb 100%);
        }

        .otp-page {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 18px;
        }

        .otp-card {
            width: min(100%, 520px);
            padding: 38px;
            border: 1px solid rgba(255, 255, 255, 0.74);
            border-radius: 20px;
            background: var(--otp-surface);
            box-shadow: 0 26px 70px rgba(15, 23, 42, 0.16);
            backdrop-filter: blur(18px);
            animation: cardIn 0.42s ease both;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(14px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .otp-visual {
            width: 76px;
            height: 76px;
            display: grid;
            place-items: center;
            margin: 0 auto 20px;
            border-radius: 20px;
            color: #ffffff;
            background: linear-gradient(135deg, var(--otp-primary), #1d4ed8);
            box-shadow: 0 16px 32px rgba(37, 99, 235, 0.28);
            font-size: 1.9rem;
        }

        .otp-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .otp-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 30px;
            padding: 5px 10px;
            border: 1px solid #dbeafe;
            border-radius: 999px;
            color: var(--otp-primary);
            background: #eff6ff;
            font-size: 0.78rem;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .otp-header h1 {
            margin: 0 0 10px;
            font-size: clamp(1.75rem, 4vw, 2.25rem);
            line-height: 1.1;
            font-weight: 850;
            letter-spacing: 0;
        }

        .otp-header p {
            margin: 0 auto;
            max-width: 410px;
            color: var(--otp-muted);
            line-height: 1.6;
            font-size: 0.96rem;
        }

        .recipient-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            padding: 9px 12px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid var(--otp-border);
            color: #334155;
            font-size: 0.88rem;
            font-weight: 700;
        }

        .otp-alert {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 18px;
            padding: 13px 14px;
            border-radius: 14px;
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .otp-alert.error {
            color: #991b1b;
            border: 1px solid #fecaca;
            background: #fef2f2;
        }

        .otp-alert.success {
            color: #065f46;
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
        }

        .otp-form {
            display: grid;
            gap: 18px;
        }

        .otp-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .otp-label-row label {
            font-weight: 800;
            color: #1f2937;
        }

        .otp-countdown {
            color: var(--otp-muted);
            font-size: 0.84rem;
            font-weight: 700;
        }

        .otp-boxes {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
        }

        .otp-digit {
            width: 100%;
            aspect-ratio: 1;
            min-height: 56px;
            border: 1.5px solid #cbd5e1;
            border-radius: 14px;
            background: #ffffff;
            color: #0f172a;
            text-align: center;
            font-size: 1.35rem;
            font-weight: 850;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        .otp-digit:focus {
            border-color: var(--otp-primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.14);
            transform: translateY(-1px);
        }

        .otp-boxes.is-error .otp-digit {
            border-color: var(--otp-error);
        }

        .otp-boxes.is-success .otp-digit {
            border-color: var(--otp-success);
        }

        .otp-actions {
            display: grid;
            gap: 12px;
            margin-top: 2px;
        }

        .otp-btn {
            min-height: 50px;
            border-radius: 14px;
            border: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            font-weight: 800;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .otp-btn:hover {
            transform: translateY(-1px);
        }

        .otp-btn.primary {
            color: #ffffff;
            background: var(--otp-primary);
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.24);
        }

        .otp-btn.primary:hover {
            background: #1d4ed8;
        }

        .otp-btn.secondary {
            color: #1f2937;
            background: #ffffff;
            border: 1px solid var(--otp-border);
        }

        .otp-btn.secondary:hover {
            background: #f8fafc;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08);
        }

        .otp-btn:disabled {
            cursor: not-allowed;
            opacity: 0.64;
            transform: none;
            box-shadow: none;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.5);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
            display: none;
        }

        .otp-btn.loading .spinner {
            display: inline-block;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .secure-note {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-top: 20px;
            color: var(--otp-muted);
            font-size: 0.84rem;
            line-height: 1.5;
        }

        .secure-note i {
            color: var(--otp-success);
            margin-top: 2px;
        }

        @media (max-width: 576px) {
            .otp-page {
                padding: 20px 12px;
            }

            .otp-card {
                padding: 28px 18px;
                border-radius: 18px;
            }

            .otp-boxes {
                gap: 7px;
            }

            .otp-digit {
                min-height: 46px;
                border-radius: 12px;
                font-size: 1.1rem;
            }

            .otp-label-row {
                align-items: flex-start;
                flex-direction: column;
                gap: 4px;
            }
        }
    </style>
</head>
<body>
    <main class="otp-page">
        <section class="otp-card" aria-labelledby="otpTitle">
            <div class="otp-visual" aria-hidden="true">
                <i class="fas <?php echo $method === 'mobile' ? 'fa-mobile-screen-button' : 'fa-envelope-circle-check'; ?>"></i>
            </div>

            <header class="otp-header">
                <span class="otp-eyebrow"><i class="fas fa-shield-halved"></i> Secure one-time password</span>
                <h1 id="otpTitle"><?php echo e($verification_title); ?></h1>
                <p>Enter the 6-digit code we sent to your <?php echo e($recipient_label); ?>. The code expires after 5 minutes.</p>
                <div class="recipient-pill">
                    <i class="fas <?php echo $method === 'mobile' ? 'fa-mobile-screen' : 'fa-envelope'; ?>"></i>
                    <span>Code sent to <?php echo e($masked_recipient); ?></span>
                </div>
            </header>

            <?php if ($error): ?>
                <div class="otp-alert error" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo e($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="otp-alert success" role="status">
                    <i class="fas fa-circle-check"></i>
                    <span><?php echo e($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="otp-form" id="verifyForm" novalidate>
                <input type="hidden" name="user_id" value="<?php echo intval($user_id); ?>">
                <input type="hidden" name="email" value="<?php echo e($email); ?>">
                <input type="hidden" name="contact" value="<?php echo e($otp_recipient); ?>">
                <input type="hidden" name="method" value="<?php echo e($method); ?>">
                <input type="hidden" name="purpose" value="<?php echo e($purpose); ?>">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" id="otp" name="otp" value="">

                <div>
                    <div class="otp-label-row">
                        <label for="otpDigit1">Verification code</label>
                        <span class="otp-countdown" id="countdown">Resend available in 01:00</span>
                    </div>
                    <div class="otp-boxes <?php echo $error ? 'is-error' : ($success ? 'is-success' : ''); ?>" id="otpBoxes" aria-label="Six digit verification code">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <input class="otp-digit" id="otpDigit<?php echo $i; ?>" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" aria-label="Digit <?php echo $i; ?>" <?php echo $i === 1 ? 'autofocus' : ''; ?>>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="otp-actions">
                    <button type="submit" class="otp-btn primary" id="verifyBtn">
                        <span class="spinner" aria-hidden="true"></span>
                        <i class="fas fa-circle-check"></i>
                        <span>Verify OTP</span>
                    </button>
                </div>
            </form>

            <form method="POST" class="otp-actions" id="resendForm">
                <input type="hidden" name="user_id" value="<?php echo intval($user_id); ?>">
                <input type="hidden" name="email" value="<?php echo e($email); ?>">
                <input type="hidden" name="contact" value="<?php echo e($otp_recipient); ?>">
                <input type="hidden" name="method" value="<?php echo e($method); ?>">
                <input type="hidden" name="purpose" value="<?php echo e($purpose); ?>">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="otp-btn secondary" id="resendBtn" disabled>
                    <i class="fas fa-rotate"></i>
                    <span>Resend Code</span>
                </button>
            </form>

            <div class="secure-note">
                <i class="fas fa-lock"></i>
                <span>For your security, never share this code with anyone. Parish staff will not ask for your OTP.</span>
            </div>
        </section>
    </main>

    <script>
        const digitInputs = Array.from(document.querySelectorAll('.otp-digit'));
        const otpHidden = document.getElementById('otp');
        const otpBoxes = document.getElementById('otpBoxes');
        const verifyForm = document.getElementById('verifyForm');
        const verifyBtn = document.getElementById('verifyBtn');
        const resendForm = document.getElementById('resendForm');
        const resendBtn = document.getElementById('resendBtn');
        const countdown = document.getElementById('countdown');

        function syncOtp() {
            const value = digitInputs.map(input => input.value.replace(/\D/g, '')).join('');
            otpHidden.value = value;
            otpBoxes.classList.toggle('is-success', value.length === 6);
            if (value.length === 6) {
                otpBoxes.classList.remove('is-error');
            }
            return value;
        }

        digitInputs.forEach((input, index) => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 1);
                if (input.value && digitInputs[index + 1]) {
                    digitInputs[index + 1].focus();
                }
                syncOtp();
            });

            input.addEventListener('keydown', event => {
                if (event.key === 'Backspace' && !input.value && digitInputs[index - 1]) {
                    digitInputs[index - 1].focus();
                }
            });

            input.addEventListener('paste', event => {
                event.preventDefault();
                const pasted = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                pasted.split('').forEach((digit, pasteIndex) => {
                    if (digitInputs[pasteIndex]) {
                        digitInputs[pasteIndex].value = digit;
                    }
                });
                const next = digitInputs[Math.min(pasted.length, 5)];
                if (next) next.focus();
                syncOtp();
            });
        });

        verifyForm.addEventListener('submit', event => {
            const otp = syncOtp();
            if (otp.length !== 6) {
                event.preventDefault();
                otpBoxes.classList.add('is-error');
                digitInputs[Math.max(0, otp.length)].focus();
                return;
            }
            verifyBtn.classList.add('loading');
            verifyBtn.disabled = true;
        });

        resendForm.addEventListener('submit', () => {
            resendBtn.disabled = true;
            resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Sending Code</span>';
        });

        let remaining = 60;
        const timer = setInterval(() => {
            const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
            const seconds = String(remaining % 60).padStart(2, '0');
            countdown.textContent = remaining > 0 ? `Resend available in ${minutes}:${seconds}` : 'You can request a new code now';
            resendBtn.disabled = remaining > 0;
            if (remaining <= 0) {
                clearInterval(timer);
            }
            remaining--;
        }, 1000);
    </script>
</body>
</html>
