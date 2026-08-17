<?php
/**
 * Email OTP Demo - Plain localhost test page for the JSON OTP API.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email OTP Demo | TUGON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body class="bg-light">
    <main class="container py-5" style="max-width:640px;">
        <div class="bg-white border rounded-3 p-4 shadow-sm">
            <h1 class="h3 mb-3">Email OTP Test</h1>
            <p class="text-muted">Use an existing unverified user email, then check your local SMTP inbox for the code.</p>

            <div id="notice" class="alert d-none" role="status"></div>

            <form id="sendForm" class="mb-4">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control mb-3" id="email" name="email" type="email" autocomplete="email" required>
                <button class="btn btn-primary" type="submit">Send OTP</button>
                <button class="btn btn-outline-secondary" id="resendBtn" type="button" disabled>Resend OTP</button>
            </form>

            <form id="verifyForm">
                <label class="form-label" for="otp">6-digit code</label>
                <input class="form-control mb-3" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                <button class="btn btn-success" type="submit">Verify OTP</button>
            </form>
        </div>
    </main>

    <script>
        const notice = document.getElementById('notice');
        const email = document.getElementById('email');
        const otp = document.getElementById('otp');
        const resendBtn = document.getElementById('resendBtn');

        function showNotice(type, message) {
            notice.className = `alert alert-${type}`;
            notice.textContent = message;
        }

        async function otpRequest(payload) {
            const response = await fetch('../api/email-otp.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Request failed.');
            }
            return data;
        }

        function startResendCooldown() {
            let remaining = 60;
            resendBtn.disabled = true;
            const timer = setInterval(() => {
                resendBtn.textContent = remaining > 0 ? `Resend in ${remaining}s` : 'Resend OTP';
                if (remaining <= 0) {
                    clearInterval(timer);
                    resendBtn.disabled = false;
                }
                remaining--;
            }, 1000);
        }

        document.getElementById('sendForm').addEventListener('submit', async event => {
            event.preventDefault();
            try {
                const data = await otpRequest({action: 'send', email: email.value, purpose: 'registration'});
                showNotice('success', data.message);
                startResendCooldown();
            } catch (error) {
                showNotice('danger', error.message);
            }
        });

        resendBtn.addEventListener('click', async () => {
            try {
                const data = await otpRequest({action: 'resend', email: email.value, purpose: 'registration'});
                showNotice('success', data.message);
                startResendCooldown();
            } catch (error) {
                showNotice('danger', error.message);
            }
        });

        document.getElementById('verifyForm').addEventListener('submit', async event => {
            event.preventDefault();
            try {
                const data = await otpRequest({action: 'verify', email: email.value, otp: otp.value, purpose: 'registration'});
                showNotice('success', data.message);
            } catch (error) {
                showNotice('danger', error.message);
            }
        });
    </script>
</body>
</html>
