<?php
/**
 * Forgot Password Page
 * Provides a user-facing recovery request screen.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../includes/helpers.php';

$message = '';
$email_input = '';
$logo_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'san-lorenzo-logo.png';
$has_logo = is_file($logo_file);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $email_input = htmlspecialchars($email);

    if (empty($email)) {
        $message = 'Please enter your registered Gmail address.';
    } elseif (!isValidEmail($email)) {
        $message = 'Please enter a valid email address.';
    } else {
        $message = 'Your recovery request has been noted. Please contact the parish office or system administrator to verify your account and reset your password.';
    }
}
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
                <span class="auth-eyebrow"><i class="fas fa-key"></i> Account Recovery</span>
                <h1>Forgot Password?</h1>
                <p>Enter your registered email address so the parish office can help verify your account recovery request.</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-info auth-message" role="status">
                    <i class="fas fa-circle-info"></i> <?php echo e($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="auth-form">
                <div class="auth-field">
                    <label for="email" class="form-label">Registered Email Address</label>
                    <div class="auth-input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo $email_input; ?>" autocomplete="email" placeholder="name@gmail.com" required autofocus>
                    </div>
                </div>

                <button type="submit" class="auth-submit">
                    <i class="fas fa-paper-plane"></i> Request Help
                </button>
            </form>

            <p class="auth-switch">
                Remembered your password? <a href="login.php">Back to Login</a>
            </p>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
