<?php

/** Compatibility facade for the centralized Phase 2 authentication layer. */
require_once __DIR__ . '/helpers.php';

function initSession(): void {
    // Session creation and cookie policy are owned by includes/session.php.
}

function isAuthenticated(): bool {
    return isLoggedIn();
}

function getUserRole() {
    return $_SESSION['role'] ?? null;
}

function isParishioner(): bool {
    return isUser();
}

function getUserFullName(): string {
    return (string) ($_SESSION['fullname'] ?? 'Guest');
}

function getUserEmail(): string {
    return (string) ($_SESSION['email'] ?? '');
}

function requireAuth(): void {
    requireAuthentication();
}

function requireParishioner(): void {
    requireAuthentication();
    if (!isUser()) {
        if (isAdmin()) {
            header('Location: ' . BASE_URL . 'admin/dashboard.php', true, 302);
            exit;
        }
        http_response_code(403);
        exit('Access denied.');
    }
}

function logoutUser(): void {
    $hadUser = !empty($_SESSION['user_id']);
    clearAuthenticationSession();
    if ($hadUser) {
        session_start();
        session_regenerate_id(true);
        queueActionNotification('Logout successful.', 'success');
    }
    header('Location: ' . BASE_URL . 'auth/login.php', true, 302);
    exit;
}

function redirectAfterLogin(): void {
    if (passwordChangeIsEnforced() && !empty($_SESSION['must_change_password'])) {
        header('Location: ' . BASE_URL . 'auth/change-password.php?required=1', true, 302);
        exit;
    }
    queueActionNotification('Login successful. Welcome back!', 'success');
    header('Location: ' . getUserDashboardURL(), true, 302);
    exit;
}

function isSessionExpired($timeout = null): bool {
    if (!isAuthenticated()) {
        return false;
    }
    $timeout = $timeout !== null ? (int) $timeout : (defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800);
    return isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $timeout;
}
