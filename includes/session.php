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

// Only start session if one hasn't been started
if (session_status() === PHP_SESSION_NONE) {
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

// Session timeout check
// Skip timeout check on login/auth pages to prevent redirect loops
$current_file = basename($_SERVER['PHP_SELF']);
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

if (!empty($_SESSION['user_id'])) {
    $regenerate_interval = defined('SESSION_REGENERATE_INTERVAL') ? SESSION_REGENERATE_INTERVAL : 5 * 60;
    if (!isset($_SESSION['session_regenerated_at']) || (time() - $_SESSION['session_regenerated_at']) > $regenerate_interval) {
        session_regenerate_id(true);
        $_SESSION['session_regenerated_at'] = time();
    }
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();
?>
