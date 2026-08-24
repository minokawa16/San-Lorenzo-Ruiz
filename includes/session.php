<?php
/**
 * Centralized Session Management
 * Safe session initialization - prevents duplicate session_start() warnings
 * 
 * Usage: Include this file at the very beginning of every page that needs a session
 * <?php include '../includes/session.php'; ?>
 */

if (!defined('SESSION_TIMEOUT')) {
    $security_config = __DIR__ . '/../config/security.php';
    if (is_file($security_config)) {
        include_once $security_config;
    }
}
require_once __DIR__ . '/security-middleware.php';
applySecurityHeaders();

// Only start session if one hasn't been started
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('TUGONSESSID');
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $requestIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    $secure = defined('SESSION_COOKIE_SECURE') ? SESSION_COOKIE_SECURE : $requestIsHttps;
    $httponly = defined('SESSION_COOKIE_HTTPONLY') ? SESSION_COOKIE_HTTPONLY : true;
    $samesite = defined('SESSION_COOKIE_SAMESITE') ? SESSION_COOKIE_SAMESITE : 'Lax';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => $httponly,
        'samesite' => $samesite,
    ]);
    @session_start();
}

if (!function_exists('ensureCentralSession')) {
    function ensureCentralSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            require __FILE__;
        }
    }
}

$sessionFingerprint = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
if (!empty($_SESSION['fully_authenticated'])) {
    if (!empty($_SESSION['session_fingerprint']) && !hash_equals($_SESSION['session_fingerprint'], $sessionFingerprint)) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../auth/login.php?session=invalid');
        exit;
    }
    $_SESSION['session_fingerprint'] = $sessionFingerprint;
}

// Session timeout check
// Skip timeout check on login/auth pages to prevent redirect loops
$current_file = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$auth_pages = ['login.php', 'register.php', 'logout.php', 'profile.php'];

if (!in_array($current_file, $auth_pages)) {
    $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 30 * 60;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        // Session expired
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?session=expired");
        exit();
    }
}

if (!empty($_SESSION['user_id']) && !empty($_SESSION['fully_authenticated'])) {
    $regenerate_interval = defined('SESSION_REGENERATE_INTERVAL') ? SESSION_REGENERATE_INTERVAL : 5 * 60;
    if (!isset($_SESSION['session_regenerated_at']) || (time() - $_SESSION['session_regenerated_at']) > $regenerate_interval) {
        session_regenerate_id(true);
        $_SESSION['session_regenerated_at'] = time();
    }
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();
?>
