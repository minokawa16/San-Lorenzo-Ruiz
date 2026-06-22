<?php
/**
 * Email Verification Module - Confirms user email addresses during registration and account recovery.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../database/config.php';
include '../includes/helpers.php';

ensureEmailNotificationSchema($conn);

$message = '';
$error = '';
$token = $_GET['token'] ?? '';

if ($token === '') {
    $error = 'Verification token is missing.';
} else {
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT verification_id, user_id, email, expires_at, verified_at FROM email_verifications WHERE token_hash = ? ORDER BY created_at DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $verification = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$verification) {
            $error = 'Verification link is invalid.';
        } elseif (!empty($verification['verified_at'])) {
            $message = 'Your Gmail account is already verified.';
        } elseif (strtotime($verification['expires_at']) < time()) {
            $error = 'Verification link has expired. Please request a new verification email from the login page.';
        } else {
            $verification_id = intval($verification['verification_id']);
            $user_id = intval($verification['user_id']);
            $conn->query("UPDATE email_verifications SET verified_at = NOW() WHERE verification_id = $verification_id");
            $conn->query("UPDATE users SET email_verified_at = NOW() WHERE id = $user_id");
            createAuditLog($conn, $user_id, 'VERIFY_EMAIL', 'users', $user_id);
            $message = 'Your Gmail account has been verified. Your registration may now continue through parish administrator review.';
        }
    } else {
        $error = 'Unable to verify email right now.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification | TUGON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css">
</head>
<body class="auth-cinematic-page">
    <main class="auth-screen" style="min-height:100vh;display:grid;place-items:center;padding:24px;">
        <section class="auth-glass-card" style="max-width:560px;width:100%;">
            <div class="auth-card-header">
                <span class="auth-eyebrow"><i class="fas fa-envelope-circle-check"></i> Gmail Verification</span>
                <h2>TUGON Account Security</h2>
                <p>Verification protects parishioner accounts and request records.</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?php echo e($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo e($error); ?></div>
            <?php endif; ?>

            <a href="login.php" class="auth-submit text-center text-decoration-none"><i class="fas fa-right-to-bracket"></i> Go to Login</a>
        </section>
    </main>
</body>
</html>
