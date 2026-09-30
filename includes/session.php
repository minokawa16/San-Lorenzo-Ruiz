<?php
/**
 * Centralized Session Management
 * Safe session initialization - prevents duplicate session_start() warnings
 * 
 * Usage: Include this file at the very beginning of every page that needs a session
 * <?php include '../includes/session.php'; ?>
 */

if (ob_get_level() === 0) {
    ob_start();
}

// ── .env loader ──────────────────────────────────────────────────────────────
// On XAMPP / local development the web server does not inject environment
// variables from the .env file, so we load it manually here (once per process).
// On Railway / Vercel the OS-level env vars are already set; this block is a
// safe no-op because we only call putenv() when getenv() returns nothing.
if (!defined('TUGON_ENV_LOADED')) {
    define('TUGON_ENV_LOADED', true);
    $envFile = __DIR__ . '/../.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $envLine) {
            $envLine = trim($envLine);
            if ($envLine === '' || $envLine[0] === '#') {
                continue;
            }
            $eqPos = strpos($envLine, '=');
            if ($eqPos === false) {
                continue;
            }
            $envKey   = trim(substr($envLine, 0, $eqPos));
            $envValue = trim(substr($envLine, $eqPos + 1));
            // Strip surrounding quotes if present
            if (strlen($envValue) >= 2
                && (($envValue[0] === '"' && substr($envValue, -1) === '"')
                    || ($envValue[0] === "'" && substr($envValue, -1) === "'"))) {
                $envValue = substr($envValue, 1, -1);
            }
            // Only set if not already defined by OS / hosting environment
            if ($envKey !== '' && getenv($envKey) === false) {
                putenv($envKey . '=' . $envValue);
                $_ENV[$envKey] = $envValue;
            }
        }
    }
}
// ── end .env loader ──────────────────────────────────────────────────────────

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

// Session timeout check
// Skip timeout check on login/auth pages to prevent redirect loops
$current_file = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$auth_pages = ['login.php', 'register.php', 'logout.php', 'profile.php'];

if (!in_array($current_file, $auth_pages)) {
    $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 30 * 60;
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $timeout)) {
        // Session expired
        session_unset();
        session_destroy();
        $loginUrl = defined('BASE_URL') ? BASE_URL . 'auth/login.php?session=expired' : '../auth/login.php?session=expired';
        header("Location: " . $loginUrl);
        exit();
    }
}

// Non-blocking session regeneration to prevent race conditions on mobile requests
if (!empty($_SESSION['user_id']) && !empty($_SESSION['fully_authenticated'])) {
    $regenerate_interval = defined('SESSION_REGENERATE_INTERVAL') ? SESSION_REGENERATE_INTERVAL : 5 * 60;
    if (!isset($_SESSION['session_regenerated_at']) || (time() - (int)$_SESSION['session_regenerated_at']) > $regenerate_interval) {
        session_regenerate_id(false);
        $_SESSION['session_regenerated_at'] = time();
    }
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();
