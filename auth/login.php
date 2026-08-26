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

// If already logged in, redirect to appropriate dashboard
// Check if session variables actually exist (not just the array)
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
$logo_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'san-lorenzo-logo.png';
$has_logo = is_file($logo_file);

if ($csrf_error !== '') {
    $error = $csrf_error;
}

if (isset($_GET['session']) && $_GET['session'] === 'expired') {
    queueActionNotification('Session expired. Please log in again.', 'warning');
}
if (isset($_GET['error']) && $_GET['error'] === 'forbidden') {
    queueActionNotification('Access denied. Please log in with an authorized account.', 'error');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | San Lorenzo Ruiz Mission Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php
    $style_version = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
    $premium_style_version = file_exists(__DIR__ . '/../assets/css/premium-parish.css') ? filemtime(__DIR__ . '/../assets/css/premium-parish.css') : time();
    $theme_style_version = file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time();
    ?>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo $style_version; ?>">
    <link rel="stylesheet" href="../assets/css/premium-parish.css?v=<?php echo $premium_style_version; ?>">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo $theme_style_version; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Playfair+Display:wght@600;700&amp;display=swap" rel="stylesheet">
    <style>
        :root {
            --login-ocean: #08739A;
            --login-link: #149BB5;
            --login-teal: #2AA6AF;
            --login-aqua: #91C2B9;
            --login-sand: #EEDCC5;
            --login-border: #D2D8D3;
            --login-text: #203238;
            --login-muted: #52686B;
        }

        body.auth-cinematic-page {
            background:
                linear-gradient(90deg, rgba(8, 115, 154, 0.38), rgba(32, 50, 56, 0.14) 45%, rgba(32, 50, 56, 0.58)),
                linear-gradient(180deg, rgba(8, 115, 154, 0.12), rgba(32, 50, 56, 0.62)),
                url("../church%20image.png") center center / cover no-repeat fixed !important;
            color: var(--login-text) !important;
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
                linear-gradient(90deg, rgba(8, 115, 154, 0.38), rgba(32, 50, 56, 0.1) 45%, rgba(32, 50, 56, 0.58)) !important;
        }

        .auth-login-screen {
            width: min(1120px, calc(100% - 32px)) !important;
            min-height: 660px !important;
            align-items: stretch !important;
            gap: 0 !important;
            border-radius: 20px !important;
            overflow: hidden !important;
            background: #FFFFFF !important;
            border: 1px solid rgba(255, 255, 255, 0.78) !important;
            box-shadow: 0 34px 90px rgba(8, 115, 154, 0.24), inset 0 2px 0 rgba(255, 255, 255, 0.8) !important;
        }

        .auth-login-side {
            position: relative !important;
            width: min(43vw, 500px) !important;
            justify-content: center !important;
            background:
                linear-gradient(90deg, rgba(255, 255, 255, 0.08) 1px, transparent 1px),
                linear-gradient(180deg, rgba(255, 255, 255, 0.08) 1px, transparent 1px),
                radial-gradient(circle at 90% 14%, rgba(255, 255, 255, 0.16), transparent 26%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0)),
                rgba(8, 115, 154, 0.94) !important;
            background-size: 72px 72px, 72px 72px, auto, auto, auto !important;
            color: #FFFFFF !important;
            border-right: 0 !important;
            padding: clamp(36px, 4vw, 58px) !important;
        }

        .auth-login-side::before {
            content: "";
            position: absolute;
            top: 34px;
            right: 34px;
            width: 112px;
            height: 112px;
            border-top: 1px solid rgba(238, 220, 197, 0.38);
            border-right: 1px solid rgba(238, 220, 197, 0.38);
            border-radius: 0 34px 0 0;
            pointer-events: none;
        }

        .auth-login-side::after {
            content: "\f654";
            position: absolute;
            right: 36px;
            bottom: 34px;
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            font-size: 5.5rem;
            line-height: 1;
            color: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        .auth-login-side *,
        .auth-login-side p,
        .auth-login-side blockquote,
        .auth-login-side strong,
        .auth-login-side small,
        .auth-login-side span {
            color: #FFFFFF !important;
        }

        .auth-side-logo {
            width: 174px !important;
            height: 174px !important;
            flex: 0 0 174px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 50% !important;
            background: #fff8eb !important;
            border: 3px solid #c39b2a !important;
            box-shadow:
                0 0 0 6px rgba(20, 16, 13, 0.92),
                0 0 0 8px rgba(246, 217, 139, 0.72),
                0 22px 48px rgba(0, 0, 0, 0.34) !important;
            overflow: hidden !important;
        }

        .auth-side-brand strong {
            font-family: Georgia, "Times New Roman", serif !important;
            font-size: clamp(2rem, 3vw, 2.55rem) !important;
            line-height: 1.08 !important;
            letter-spacing: 0 !important;
        }

        .auth-side-brand small {
            color: rgba(238, 220, 197, 0.96) !important;
            font-size: 0.86rem !important;
            letter-spacing: 0.08em !important;
        }

        .auth-login-side blockquote {
            margin-top: 28px !important;
            max-width: 360px !important;
            font-family: Georgia, "Times New Roman", serif !important;
            font-size: clamp(1.16rem, 1.6vw, 1.34rem) !important;
            font-weight: 700 !important;
            line-height: 1.45 !important;
        }

        .auth-login-side p {
            max-width: 370px !important;
            color: rgba(255, 255, 255, 0.92) !important;
            font-size: 16px !important;
            line-height: 1.65 !important;
        }

        .auth-copy-list span {
            min-height: 44px !important;
            background: rgba(255, 255, 255, 0.13) !important;
            border: 1px solid rgba(255, 255, 255, 0.28) !important;
            border-radius: 999px !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            backdrop-filter: blur(8px) !important;
        }

        .auth-glass-card.auth-login-card {
            position: relative !important;
            width: min(57vw, 620px) !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            background: radial-gradient(circle at 96% 8%, rgba(145, 194, 185, 0.2), transparent 28%), #FFFFFF !important;
            border: 0 !important;
            border-radius: 0 !important;
            color: var(--login-text) !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            padding: clamp(46px, 5vw, 76px) !important;
        }

        .auth-glass-card.auth-login-card::before {
            content: "Official Parish Portal";
            align-self: flex-start;
            min-height: 34px;
            margin-bottom: 18px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(8, 115, 154, 0.1);
            border: 1px solid rgba(8, 115, 154, 0.18);
            color: var(--login-ocean);
            font-size: 14px;
            font-weight: 700;
        }

        .auth-glass-card.auth-login-card::after {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: linear-gradient(180deg, var(--login-ocean), var(--login-teal), var(--login-sand));
        }

        .auth-card-header h2 {
            color: var(--login-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
            font-size: clamp(28px, 3vw, 36px) !important;
            font-weight: 700 !important;
            line-height: 1.25 !important;
            letter-spacing: 0 !important;
            margin-bottom: 8px !important;
        }

        .auth-card-header p,
        .auth-switch,
        .auth-check span {
            color: var(--login-muted) !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
        }

        .auth-form {
            margin-top: 26px !important;
        }

        .auth-field {
            margin-bottom: 18px !important;
        }

        .auth-form .form-label {
            color: var(--login-text) !important;
            font-size: 15px !important;
            font-weight: 600 !important;
            margin-bottom: 8px !important;
        }

        .auth-input-wrap {
            background: #FFFFFF !important;
            border: 1px solid var(--login-border) !important;
            border-radius: 10px !important;
            min-height: 52px !important;
            box-shadow: 0 1px 0 rgba(32, 50, 56, 0.02) !important;
        }

        .auth-input-wrap i,
        .auth-password-toggle {
            color: var(--login-teal) !important;
        }

        .auth-input-wrap .form-control {
            color: var(--login-text) !important;
            -webkit-text-fill-color: var(--login-text) !important;
            font-size: 16px !important;
            min-height: 52px !important;
        }

        .auth-input-wrap:focus-within {
            border-color: var(--login-link) !important;
            box-shadow: 0 0 0 4px rgba(20, 155, 181, 0.18) !important;
        }

        .auth-options {
            margin: 8px 0 22px !important;
        }

        .auth-link,
        .auth-switch a {
            color: var(--login-link) !important;
            font-size: 15px !important;
            font-weight: 600 !important;
            text-decoration: none !important;
        }

        .auth-link:hover,
        .auth-switch a:hover {
            color: var(--login-ocean) !important;
            text-decoration: underline !important;
        }

        .auth-submit {
            background: linear-gradient(135deg, var(--login-link), var(--login-ocean)) !important;
            border: 1px solid rgba(8, 115, 154, 0.16) !important;
            color: #FFFFFF !important;
            border-radius: 999px !important;
            min-height: 52px !important;
            font-size: 16px !important;
            font-weight: 700 !important;
            box-shadow: 0 16px 34px rgba(20, 155, 181, 0.25) !important;
        }

        .auth-submit *,
        .auth-submit i {
            color: #FFFFFF !important;
        }

        .auth-submit:hover,
        .auth-submit:focus-visible {
            background: linear-gradient(135deg, var(--login-ocean), #065f80) !important;
            border-color: var(--login-ocean) !important;
            transform: translateY(-1px) !important;
        }

        .auth-verification-actions {
            gap: 12px !important;
            margin-top: 16px !important;
        }

        .auth-social-btn {
            min-height: 48px !important;
            background: #FFFFFF !important;
            border: 1px solid var(--login-border) !important;
            border-radius: 999px !important;
            color: var(--login-ocean) !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            box-shadow: 0 8px 18px rgba(8, 115, 154, 0.06) !important;
        }

        .auth-social-btn i {
            color: var(--login-ocean) !important;
        }

        .auth-social-btn:hover,
        .auth-social-btn:focus-visible {
            background: rgba(145, 194, 185, 0.18) !important;
            border-color: var(--login-link) !important;
            color: var(--login-ocean) !important;
        }

        .auth-message {
            background: #FFFFFF !important;
            border: 1px solid var(--login-border) !important;
            color: var(--login-text) !important;
            font-size: 15px !important;
            line-height: 1.5 !important;
            border-radius: 10px !important;
        }

        .auth-switch {
            margin-top: 22px !important;
            text-align: center !important;
        }

        @media (max-width: 900px) {
            .auth-login-screen {
                display: grid !important;
                width: min(100% - 24px, 720px) !important;
                min-height: auto !important;
            }

            .auth-login-side,
            .auth-glass-card.auth-login-card {
                width: 100% !important;
            }

            .auth-login-side {
                min-height: auto !important;
                padding: 34px 26px !important;
            }

            .auth-glass-card.auth-login-card {
                padding: 34px 24px !important;
            }
        }

        @media (max-width: 560px) {
            .auth-login-screen {
                width: min(100% - 18px, 520px) !important;
                border-radius: 16px !important;
            }

            .auth-verification-actions {
                grid-template-columns: 1fr !important;
            }
        }

        /* Warm cream/gold login restoration. */
        :root {
            --login-cream: #FFF8EB;
            --login-cream-soft: #FAF6EE;
            --login-gold: #D4A94E;
            --login-gold-deep: #B88A22;
            --login-gold-soft: #F6DF9F;
            --login-ink: #1C1B18;
            --login-muted-warm: #6F675A;
            --login-warm-border: #DFCFAA;
        }

        body.auth-cinematic-page {
            background:
                linear-gradient(90deg, rgba(15, 10, 6, 0.62) 0%, rgba(15, 10, 6, 0.24) 44%, rgba(8, 11, 18, 0.78) 100%),
                linear-gradient(180deg, rgba(0, 0, 0, 0.22), rgba(0, 0, 0, 0.62)),
                url("../church%20image.png") center center / cover no-repeat fixed !important;
            color: var(--login-ink) !important;
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

        .auth-login-screen {
            width: min(1120px, calc(100% - 32px)) !important;
            min-height: 660px !important;
            background: var(--login-cream) !important;
            border: 1px solid rgba(255, 248, 235, 0.68) !important;
            border-radius: 14px !important;
            box-shadow: 0 34px 90px rgba(0, 0, 0, 0.32) !important;
        }

        .auth-login-side {
            background:
                radial-gradient(circle at 88% 12%, rgba(212, 169, 78, 0.2), transparent 28%),
                linear-gradient(135deg, rgba(28, 27, 24, 0.96), rgba(39, 35, 29, 0.94)) !important;
            color: #FFF8EB !important;
            padding: clamp(36px, 4vw, 58px) !important;
        }

        .auth-login-side::before {
            border-color: rgba(212, 169, 78, 0.32) !important;
        }

        .auth-login-side::after {
            color: rgba(212, 169, 78, 0.08) !important;
        }

        .auth-login-side *,
        .auth-login-side p,
        .auth-login-side blockquote,
        .auth-login-side strong,
        .auth-login-side small,
        .auth-login-side span {
            color: #FFF8EB !important;
        }

        .auth-side-logo {
            width: 174px !important;
            height: 174px !important;
            flex: 0 0 174px !important;
            border-radius: 50% !important;
            background: #fff8eb !important;
            border: 3px solid #c39b2a !important;
            box-shadow:
                0 0 0 6px rgba(20, 16, 13, 0.92),
                0 0 0 8px rgba(246, 217, 139, 0.72),
                0 22px 48px rgba(0, 0, 0, 0.34) !important;
        }

        .auth-side-brand small {
            color: var(--login-gold-soft) !important;
        }

        .auth-copy-list span {
            background: rgba(255, 248, 235, 0.08) !important;
            border-color: rgba(212, 169, 78, 0.28) !important;
        }

        .auth-copy-list i {
            color: var(--login-gold-soft) !important;
        }

        .auth-glass-card.auth-login-card {
            background:
                radial-gradient(circle at 96% 8%, rgba(246, 223, 159, 0.28), transparent 28%),
                var(--login-cream) !important;
            color: var(--login-ink) !important;
            padding: clamp(46px, 5vw, 76px) !important;
        }

        .auth-glass-card.auth-login-card::before {
            content: "Parish Account Access";
            background: rgba(212, 169, 78, 0.16) !important;
            border-color: rgba(212, 169, 78, 0.34) !important;
            color: var(--login-ink) !important;
        }

        .auth-glass-card.auth-login-card::after {
            background: linear-gradient(180deg, var(--login-gold-deep), var(--login-gold), var(--login-gold-soft)) !important;
        }

        .auth-card-header h2,
        .auth-form .form-label {
            color: var(--login-ink) !important;
        }

        .auth-card-header p,
        .auth-switch,
        .auth-check span {
            color: var(--login-muted-warm) !important;
        }

        .auth-input-wrap {
            background: #FFFFFF !important;
            border-color: var(--login-warm-border) !important;
        }

        .auth-input-wrap i,
        .auth-password-toggle {
            color: var(--login-gold-deep) !important;
        }

        .auth-input-wrap:focus-within {
            border-color: var(--login-gold) !important;
            box-shadow: 0 0 0 4px rgba(212, 169, 78, 0.18) !important;
        }

        .auth-submit {
            background: linear-gradient(135deg, var(--login-gold), var(--login-gold-deep)) !important;
            border-color: var(--login-gold-deep) !important;
            color: var(--login-ink) !important;
            box-shadow: 0 16px 34px rgba(212, 169, 78, 0.24) !important;
        }

        .auth-submit *,
        .auth-submit i {
            color: var(--login-ink) !important;
        }

        .auth-submit:hover,
        .auth-submit:focus-visible {
            background: linear-gradient(135deg, var(--login-gold-deep), #9B741A) !important;
            border-color: #9B741A !important;
        }

        .auth-link,
        .auth-switch a,
        .auth-social-btn,
        .auth-social-btn i {
            color: var(--login-gold-deep) !important;
        }

        .auth-social-btn {
            background: #FFFFFF !important;
            border-color: var(--login-warm-border) !important;
            box-shadow: 0 8px 18px rgba(28, 27, 24, 0.06) !important;
        }

        .auth-social-btn:hover,
        .auth-social-btn:focus-visible {
            background: rgba(246, 223, 159, 0.2) !important;
            border-color: var(--login-gold) !important;
        }

        .auth-message {
            background: #FFFFFF !important;
            border-color: var(--login-warm-border) !important;
            color: var(--login-ink) !important;
        }

        /* Premium TUGON authentication redesign. */
        :root {
            --tugon-auth-gold: #C89B3C;
            --tugon-auth-gold-deep: #9F7622;
            --tugon-auth-gold-soft: #E8D6A7;
            --tugon-auth-beige: #F8F4EC;
            --tugon-auth-white: #FFFFFF;
            --tugon-auth-brown: #2F2A24;
            --tugon-auth-text: #333333;
            --tugon-auth-muted: #6F675A;
            --tugon-auth-border: #E6D8C1;
            --tugon-auth-shadow: 0 28px 80px rgba(18, 16, 13, 0.28);
        }

        body.auth-cinematic-page {
            min-height: 100vh !important;
            display: grid !important;
            place-items: center !important;
            padding: clamp(18px, 3vw, 36px) !important;
            overflow-x: hidden !important;
            background:
                linear-gradient(90deg, rgba(47, 42, 36, 0.74) 0%, rgba(47, 42, 36, 0.34) 43%, rgba(25, 22, 19, 0.82) 100%),
                linear-gradient(180deg, rgba(47, 42, 36, 0.18), rgba(47, 42, 36, 0.6)),
                url("../church%20image.png") center center / cover no-repeat fixed !important;
            color: var(--tugon-auth-text) !important;
            animation: authPageFade 420ms ease both !important;
        }

        body.auth-cinematic-page::before {
            background-image: url("../church%20image.png") !important;
            background-position: center !important;
            background-size: cover !important;
            filter: blur(2px) sepia(0.12) saturate(1.05) brightness(0.72) !important;
            transform: scale(1.03) !important;
        }

        body.auth-cinematic-page::after {
            background:
                radial-gradient(circle at 19% 14%, rgba(200, 155, 60, 0.26), transparent 26%),
                radial-gradient(circle at 74% 72%, rgba(232, 214, 167, 0.16), transparent 28%),
                linear-gradient(90deg, rgba(47, 42, 36, 0.68), rgba(47, 42, 36, 0.18) 42%, rgba(25, 22, 19, 0.74)) !important;
        }

        .auth-login-screen {
            position: relative !important;
            z-index: 1 !important;
            width: min(1180px, calc(100vw - 40px)) !important;
            min-height: min(720px, calc(100vh - 48px)) !important;
            display: grid !important;
            grid-template-columns: minmax(360px, 40%) minmax(0, 60%) !important;
            align-items: stretch !important;
            gap: 0 !important;
            overflow: hidden !important;
            border: 1px solid rgba(255, 255, 255, 0.46) !important;
            border-radius: 24px !important;
            background: rgba(255, 255, 255, 0.82) !important;
            box-shadow: var(--tugon-auth-shadow) !important;
            backdrop-filter: blur(18px) saturate(130%) !important;
            animation: authCardRise 460ms ease both !important;
        }

        .auth-login-side {
            position: relative !important;
            width: auto !important;
            min-height: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            overflow: hidden !important;
            padding: clamp(34px, 4.4vw, 64px) !important;
            background:
                linear-gradient(135deg, rgba(47, 42, 36, 0.96), rgba(38, 34, 29, 0.94)),
                #2F2A24 !important;
            color: var(--tugon-auth-white) !important;
        }

        .auth-login-side::before {
            content: "" !important;
            position: absolute !important;
            inset: 28px 28px auto auto !important;
            width: 132px !important;
            height: 132px !important;
            border-top: 1px solid rgba(200, 155, 60, 0.36) !important;
            border-right: 1px solid rgba(200, 155, 60, 0.36) !important;
            border-radius: 0 34px 0 0 !important;
        }

        .auth-login-side::after {
            content: "" !important;
            position: absolute !important;
            inset: auto -80px -120px auto !important;
            width: 280px !important;
            height: 280px !important;
            border: 1px solid rgba(200, 155, 60, 0.18) !important;
            border-radius: 50% !important;
            background: radial-gradient(circle, rgba(200, 155, 60, 0.15), transparent 62%) !important;
        }

        .auth-side-brand {
            position: relative !important;
            z-index: 2 !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            gap: 20px !important;
            width: 100% !important;
            margin: 0 auto !important;
            color: var(--tugon-auth-white) !important;
            text-decoration: none !important;
            animation: authLogoFade 520ms ease both !important;
        }

        .auth-side-logo {
            width: 180px !important;
            height: 180px !important;
            flex: 0 0 180px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto !important;
            overflow: hidden !important;
            border-radius: 50% !important;
            background: #fff8eb !important;
            border: 3px solid #c39b2a !important;
            box-shadow:
                0 0 0 6px rgba(20, 16, 13, 0.92),
                0 0 0 8px rgba(246, 217, 139, 0.72),
                0 22px 48px rgba(0, 0, 0, 0.34) !important;
        }

        .auth-side-logo img {
            width: 100% !important;
            height: 100% !important;
            display: block !important;
            object-fit: cover !important;
            border-radius: 50% !important;
            clip-path: circle(50% at 50% 50%) !important;
        }

        .auth-side-brand strong {
            display: block !important;
            color: var(--tugon-auth-white) !important;
            font-family: "Playfair Display", Georgia, serif !important;
            font-size: clamp(2rem, 3.2vw, 2.65rem) !important;
            line-height: 1.05 !important;
            letter-spacing: 0 !important;
            text-align: center !important;
            width: 100% !important;
        }

        .auth-side-brand small {
            display: inline-flex !important;
            margin-top: 5px !important;
            color: var(--tugon-auth-gold-soft) !important;
            font-size: 0.82rem !important;
            font-weight: 800 !important;
            letter-spacing: 0.13em !important;
            text-transform: uppercase !important;
            text-align: center !important;
            justify-content: center !important;
            width: 100% !important;
        }

        .auth-login-side blockquote {
            position: relative !important;
            z-index: 2 !important;
            max-width: 400px !important;
            margin: 36px auto 14px !important;
            color: var(--tugon-auth-white) !important;
            font-family: "Playfair Display", Georgia, serif !important;
            font-size: clamp(1.4rem, 2.2vw, 1.85rem) !important;
            font-weight: 700 !important;
            line-height: 1.25 !important;
            text-align: center !important;
        }

        .auth-login-side p {
            position: relative !important;
            z-index: 2 !important;
            max-width: 380px !important;
            margin: 0 auto !important;
            color: rgba(255, 255, 255, 0.86) !important;
            font-size: 0.94rem !important;
            line-height: 1.65 !important;
            text-align: center !important;
        }

        .auth-copy-list {
            position: relative !important;
            z-index: 2 !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
            width: min(100%, 450px) !important;
            margin-top: 30px !important;
        }

        .auth-copy-list span {
            min-height: 44px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 9px !important;
            padding: 10px 12px !important;
            border: 1px solid rgba(232, 214, 167, 0.28) !important;
            border-radius: 14px !important;
            background: rgba(255, 255, 255, 0.08) !important;
            color: rgba(255, 255, 255, 0.9) !important;
            font-size: 13px !important;
            font-weight: 750 !important;
            line-height: 1.2 !important;
            backdrop-filter: blur(8px) !important;
        }

        .auth-copy-list i {
            color: var(--tugon-auth-gold-soft) !important;
            font-size: 15px !important;
        }

        .auth-church-mark {
            position: absolute !important;
            right: 34px !important;
            bottom: 28px !important;
            z-index: 1 !important;
            color: rgba(232, 214, 167, 0.11) !important;
            font-size: clamp(5.5rem, 10vw, 8rem) !important;
            line-height: 1 !important;
            pointer-events: none !important;
        }

        .auth-glass-card.auth-login-card {
            width: auto !important;
            min-width: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            padding: clamp(42px, 5vw, 72px) clamp(34px, 5.8vw, 86px) !important;
            border: 0 !important;
            border-radius: 0 !important;
            background:
                radial-gradient(circle at 100% 0%, rgba(232, 214, 167, 0.24), transparent 30%),
                rgba(255, 255, 255, 0.92) !important;
            color: var(--tugon-auth-text) !important;
            box-shadow: none !important;
            backdrop-filter: blur(12px) !important;
        }

        .auth-glass-card.auth-login-card::before {
            content: "Secure TUGON Portal" !important;
            align-self: flex-start !important;
            min-height: 34px !important;
            margin-bottom: 18px !important;
            padding: 7px 13px !important;
            border-radius: 999px !important;
            border: 1px solid rgba(200, 155, 60, 0.24) !important;
            background: rgba(200, 155, 60, 0.12) !important;
            color: var(--tugon-auth-gold-deep) !important;
            font-size: 13px !important;
            font-weight: 850 !important;
        }

        .auth-glass-card.auth-login-card::after {
            width: 0 !important;
            background: transparent !important;
        }

        .auth-card-header h2 {
            margin: 0 0 8px !important;
            color: var(--tugon-auth-text) !important;
            font-family: "Playfair Display", Georgia, serif !important;
            font-size: clamp(36px, 4vw, 42px) !important;
            font-weight: 700 !important;
            line-height: 1.1 !important;
        }

        .auth-card-header p {
            max-width: 440px !important;
            margin: 0 !important;
            color: var(--tugon-auth-muted) !important;
            font-size: 17px !important;
            line-height: 1.55 !important;
        }

        .auth-form {
            width: 100% !important;
            max-width: 480px !important;
            margin-top: 28px !important;
        }

        .auth-field {
            margin-bottom: 18px !important;
        }

        .auth-form .form-label {
            margin-bottom: 8px !important;
            color: var(--tugon-auth-text) !important;
            font-size: 14px !important;
            font-weight: 800 !important;
        }

        .auth-input-wrap {
            position: relative !important;
            min-height: 52px !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            padding: 0 14px !important;
            border: 1px solid var(--tugon-auth-border) !important;
            border-radius: 16px !important;
            background: var(--tugon-auth-white) !important;
            box-shadow: 0 10px 24px rgba(47, 42, 36, 0.06) !important;
            transition: border-color 240ms ease, box-shadow 240ms ease, transform 240ms ease !important;
        }

        .auth-input-wrap:focus-within {
            border-color: var(--tugon-auth-gold) !important;
            box-shadow: 0 0 0 4px rgba(200, 155, 60, 0.16), 0 14px 30px rgba(47, 42, 36, 0.08) !important;
            transform: translateY(-1px) !important;
        }

        .auth-input-wrap i,
        .auth-password-toggle {
            color: var(--tugon-auth-gold-deep) !important;
        }

        .auth-input-wrap .form-control {
            min-height: 50px !important;
            padding: 0 !important;
            border: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            color: var(--tugon-auth-text) !important;
            -webkit-text-fill-color: var(--tugon-auth-text) !important;
            font-size: 16px !important;
        }

        .auth-password-toggle {
            width: 38px !important;
            height: 38px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border: 0 !important;
            border-radius: 12px !important;
            background: transparent !important;
        }

        .auth-password-toggle:hover,
        .auth-password-toggle:focus-visible {
            background: rgba(200, 155, 60, 0.12) !important;
            outline: 2px solid rgba(200, 155, 60, 0.2) !important;
        }

        .auth-options {
            max-width: 480px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 16px !important;
            margin: 8px 0 22px !important;
        }

        .auth-check {
            display: inline-flex !important;
            align-items: center !important;
            gap: 9px !important;
            cursor: pointer !important;
        }

        .auth-check input {
            width: 18px !important;
            height: 18px !important;
            accent-color: var(--tugon-auth-gold) !important;
        }

        .auth-check span,
        .auth-link,
        .auth-switch {
            color: var(--tugon-auth-muted) !important;
            font-size: 14px !important;
        }

        .auth-link,
        .auth-switch a {
            color: var(--tugon-auth-gold-deep) !important;
            font-weight: 850 !important;
            text-decoration: none !important;
        }

        .auth-link:hover,
        .auth-switch a:hover,
        .auth-link:focus-visible,
        .auth-switch a:focus-visible {
            color: var(--tugon-auth-gold) !important;
            text-decoration: underline !important;
        }

        .auth-submit {
            width: 100% !important;
            max-width: 480px !important;
            min-height: 54px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 10px !important;
            border: 1px solid rgba(159, 118, 34, 0.28) !important;
            border-radius: 16px !important;
            background: linear-gradient(135deg, var(--tugon-auth-gold), var(--tugon-auth-gold-deep)) !important;
            color: var(--tugon-auth-white) !important;
            font-size: 16px !important;
            font-weight: 850 !important;
            box-shadow: 0 16px 34px rgba(200, 155, 60, 0.28), 0 0 22px rgba(200, 155, 60, 0.14) !important;
            transition: transform 240ms ease, box-shadow 240ms ease, filter 240ms ease !important;
        }

        .auth-submit *,
        .auth-submit i {
            color: var(--tugon-auth-white) !important;
        }

        .auth-submit:hover,
        .auth-submit:focus-visible {
            filter: brightness(0.98) saturate(1.04) !important;
            box-shadow: 0 20px 42px rgba(200, 155, 60, 0.34), 0 0 26px rgba(200, 155, 60, 0.18) !important;
            transform: translateY(-2px) !important;
        }

        .auth-verification-actions {
            width: 100% !important;
            max-width: 480px !important;
            display: grid !important;
            gap: 10px !important;
            margin-top: 14px !important;
        }

        .auth-social-btn,
        .auth-home-link {
            min-height: 50px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 9px !important;
            border-radius: 16px !important;
            color: var(--tugon-auth-gold-deep) !important;
            font-size: 15px !important;
            font-weight: 850 !important;
            text-decoration: none !important;
            transition: transform 240ms ease, border-color 240ms ease, background 240ms ease, box-shadow 240ms ease !important;
        }

        .auth-social-btn {
            border: 1px solid rgba(200, 155, 60, 0.42) !important;
            background: var(--tugon-auth-white) !important;
            box-shadow: 0 8px 18px rgba(47, 42, 36, 0.06) !important;
        }

        .auth-home-link {
            border: 0 !important;
            background: transparent !important;
        }

        .auth-social-btn:hover,
        .auth-social-btn:focus-visible,
        .auth-home-link:hover,
        .auth-home-link:focus-visible {
            border-color: var(--tugon-auth-gold) !important;
            background: rgba(200, 155, 60, 0.1) !important;
            color: var(--tugon-auth-gold-deep) !important;
            transform: translateY(-1px) !important;
        }

        .auth-switch {
            max-width: 480px !important;
            margin: 22px 0 0 !important;
            text-align: center !important;
            font-size: 15px !important;
        }

        .auth-message {
            max-width: 480px !important;
            border-radius: 14px !important;
            border-color: var(--tugon-auth-border) !important;
            background: var(--tugon-auth-white) !important;
            color: var(--tugon-auth-text) !important;
            box-shadow: 0 8px 20px rgba(47, 42, 36, 0.06) !important;
        }

        #checkStatusForm {
            margin-top: 16px !important;
        }

        @keyframes authPageFade {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes authCardRise {
            from {
                opacity: 0;
                transform: translateY(22px) scale(0.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes authLogoFade {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 980px) {
            body.auth-cinematic-page {
                padding: 16px !important;
            }

            .auth-login-screen {
                width: min(760px, calc(100vw - 24px)) !important;
                min-height: auto !important;
                grid-template-columns: 1fr !important;
            }

            .auth-login-side {
                min-height: auto !important;
                padding: 34px 26px 38px !important;
            }

            .auth-copy-list {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .auth-glass-card.auth-login-card {
                padding: 34px 26px 38px !important;
            }
        }

        @media (max-width: 560px) {
            .auth-login-screen {
                width: min(100%, calc(100vw - 16px)) !important;
                border-radius: 20px !important;
            }

            .auth-side-brand {
                align-items: flex-start !important;
            }

            .auth-side-logo {
                width: 120px !important;
                height: 120px !important;
                flex: 0 0 120px !important;
                border-radius: 50% !important;
                border: 3px solid #c39b2a !important;
                box-shadow:
                    0 0 0 4px rgba(20, 16, 13, 0.92),
                    0 0 0 6px rgba(246, 217, 139, 0.72),
                    0 12px 28px rgba(0, 0, 0, 0.28) !important;
            }

            .auth-side-logo img {
                width: 100% !important;
                height: 100% !important;
                border-radius: 50% !important;
                clip-path: circle(50% at 50% 50%) !important;
            }

            .auth-login-side blockquote {
                margin-top: 28px !important;
                font-size: 1.65rem !important;
            }

            .auth-copy-list {
                grid-template-columns: 1fr !important;
            }

            .auth-options {
                align-items: flex-start !important;
                flex-direction: column !important;
                gap: 10px !important;
            }

            .auth-card-header h2 {
                font-size: 34px !important;
            }
        }

        /* --- GLOBAL INPUT & PREFIX ICON STANDARDIZATION --- */
        .auth-input-wrap {
            position: relative !important;
            display: block !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            box-sizing: border-box !important;
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
            padding: 10px 16px 10px 44px !important; /* CRITICAL: 44px clears prefix icon completely */
            box-sizing: border-box !important;
            line-height: 1.5 !important;
        }

        .auth-input-wrap:has(.auth-password-toggle) .form-control,
        .auth-input-wrap:has(button) .form-control {
            padding-right: 48px !important;
        }

        /* Hide native browser password reveal button in Edge/IE/WebKit */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear,
        input::-ms-reveal,
        input::-ms-clear {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
            pointer-events: none !important;
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
    </style>
    <link rel="stylesheet" href="../assets/css/login-institutional.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/login-institutional.css'); ?>">
    <link rel="stylesheet" href="../assets/css/auth-mobile.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/auth-mobile.css'); ?>">
</head>
<body class="auth-cinematic-page">
    <div class="parish-toast-container" id="parishToastContainer" aria-live="polite" aria-atomic="true"></div>
    <div class="auth-ambient" aria-hidden="true"></div>
    <main class="auth-screen auth-login-screen">
        <aside class="auth-copy auth-login-side" aria-label="System introduction">
            <a href="../index.php" class="auth-side-brand" aria-label="Back to TUGON homepage">
                <span class="auth-side-logo">
                    <?php if ($has_logo): ?>
                        <img src="../assets/img/san-lorenzo-logo.png" alt="San Lorenzo Ruiz logo">
                    <?php else: ?>
                        <i class="fas fa-church"></i>
                    <?php endif; ?>
                </span>
                <span>
                    <strong>San Lorenzo Ruiz</strong>
                    <small>Mission Station</small>
                </span>
            </a>
            <blockquote>Serving the Parish through Faith, Community, and Technology.</blockquote>
            <p>A secure digital platform for parish services, sacramental records, requests, and announcements.</p>
            <div class="auth-parish-footer">
                <i class="fas fa-church" aria-hidden="true"></i>
                <span>Faithfully serving our parish community.</span>
            </div>
        </aside>

        <section class="auth-glass-card auth-login-card" aria-label="Login form">
            <div class="auth-card-header">
                <span class="auth-ornament" aria-hidden="true"><i class="fas fa-cross"></i></span>
                <h2>Welcome Back</h2>
                <p>Sign in to access your Parish Management System account.</p>
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
                <div class="auth-field">
                    <label for="email" class="form-label">Email Address or Mobile Number</label>
                    <div class="auth-input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="text" class="form-control" id="email" name="email" value="<?php echo $identifier_input; ?>" autocomplete="username" placeholder="name@gmail.com or 09XXXXXXXXX" required autofocus>
                    </div>
                </div>

                <div class="auth-field">
                    <label for="password" class="form-label">Password</label>
                    <div class="auth-input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                    </div>
                </div>

                <div class="auth-options">
                    <label class="auth-check" for="remember">
                        <input type="checkbox" id="remember" name="remember">
                        <span>Keep me signed in</span>
                    </label>
                    <a href="forgot-password.php" class="auth-link">Forgot Password?</a>
                </div>

                <button type="submit" class="auth-submit" name="login">
                    <span class="desktop-auth-only">Access Dashboard</span><span class="mobile-auth-only">Sign In</span> <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="auth-verification-actions">
                <p class="auth-status-prompt">Need to check an existing request?</p>
                <button type="button" class="auth-social-btn auth-status-btn" id="checkStatusToggle"><i class="fas fa-magnifying-glass"></i> Check Request Status <i class="fas fa-arrow-right"></i></button>
                <a href="../index.php" class="auth-home-link"><i class="fas fa-arrow-left"></i> Back to Homepage</a>
            </div>

            <form method="POST" action="" class="auth-form" id="checkStatusForm" style="<?php echo ($status_error || $status_notice) ? '' : 'display:none;'; ?> margin-top: 14px;">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="form_action" value="check_status">
                <div class="auth-field">
                    <label for="status_email" class="form-label">Check Registration Status</label>
                    <div class="auth-input-wrap">
                        <i class="fas fa-envelope-circle-check"></i>
                        <input type="text" class="form-control" id="status_email" name="status_email" value="<?php echo $status_email_input; ?>" autocomplete="username" placeholder="Enter your registered email or mobile number">
                    </div>
                </div>
                <button type="submit" class="auth-submit">
                    <i class="fas fa-magnifying-glass"></i> Check Account
                </button>
            </form>

                        <p class="auth-switch">
                            New Parishioner? <a href="register.php">Create an Account <i class="fas fa-arrow-right"></i></a>
                        </p>

                        <p class="auth-security-note"><i class="fas fa-lock" aria-hidden="true"></i> Your information is securely protected.</p>

                        
        </section>
    </main>

    <script>
        window.parishInitialNotifications = <?php echo json_encode($action_notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
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
    <script src="../assets/js/main.js"></script>
</body>
</html>
