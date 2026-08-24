<?php

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/permissions.php';

function securitySetting(mysqli $conn, string $key, $default = null) {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $statement = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    if (!$statement) {
        return $default;
    }
    $statement->bind_param('s', $key);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $cache[$key] = ($row ? $row['setting_value'] : $default);
}

function authenticationClientIp(): string {
    return function_exists('tugonClientIp') ? tugonClientIp() : substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function authenticationUserAgent(): string {
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 500);
}

function normalizeAuthenticationIdentifier(string $identifier): array {
    $identifier = trim($identifier);
    if (isValidEmail($identifier)) {
        return ['valid' => true, 'type' => 'email', 'value' => strtolower($identifier)];
    }
    $mobile = normalizePhilippineMobileForStorage($identifier);
    if (isValidPhilippineMobile($mobile)) {
        return ['valid' => true, 'type' => 'mobile', 'value' => $mobile];
    }
    return ['valid' => false, 'type' => '', 'value' => ''];
}

function findUserByAuthenticationIdentifier(mysqli $conn, string $identifier): ?array {
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $normalized = normalizeAuthenticationIdentifier($identifier);

    // 1. First, search user_auth_identifiers table
    if ($normalized['valid']) {
        $statement = $conn->prepare(
            'SELECT u.* FROM user_auth_identifiers i JOIN users u ON u.id = i.user_id WHERE i.identifier_type = ? AND i.normalized_value = ? LIMIT 1'
        );
        if ($statement) {
            $statement->bind_param('ss', $normalized['type'], $normalized['value']);
            $statement->execute();
            $user = $statement->get_result()->fetch_assoc() ?: null;
            $statement->close();
            if ($user) {
                return $user;
            }
        }
    }

    // 2. Direct search by email
    if (isValidEmail($identifier)) {
        $stmt = $conn->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $identifier);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($user) {
                if (function_exists('synchronizeAuthenticationIdentifier') && !empty($user['email'])) {
                    synchronizeAuthenticationIdentifier($conn, (int)$user['id'], 'email', strtolower($user['email']), $user['email_verified_at'] ?? null);
                }
                return $user;
            }
        }
    }

    // 3. Direct search by phone number (robust matching on all Philippine mobile variations)
    $cleanDigits = preg_replace('/[^\d]/', '', $identifier);
    if (strlen($cleanDigits) >= 10) {
        $last10 = substr($cleanDigits, -10);
        $fmt09 = '0' . $last10;
        $fmt63 = '63' . $last10;
        $fmtPlus63 = '+63' . $last10;

        $stmt = $conn->prepare('
            SELECT * FROM users 
            WHERE phone_number = ? OR phone_number = ? OR phone_number = ? OR phone_number = ?
               OR REPLACE(REPLACE(REPLACE(phone_number, " ", ""), "-", ""), "+", "") LIKE ?
            ORDER BY (status = "active") DESC, id ASC LIMIT 1
        ');
        if ($stmt) {
            $likeLast10 = '%' . $last10;
            $stmt->bind_param('sssss', $identifier, $fmt09, $fmt63, $fmtPlus63, $likeLast10);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($user) {
                if (function_exists('synchronizeAuthenticationIdentifier')) {
                    synchronizeAuthenticationIdentifier($conn, (int)$user['id'], 'mobile', $fmt09, $user['phone_verified_at'] ?? null);
                }
                return $user;
            }
        }
    }

    return null;
}

function authenticationRolesForUser(mysqli $conn, int $userId): array {
    $statement = $conn->prepare(
        'SELECT r.role_key FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = ? ORDER BY FIELD(r.role_key, "administrator", "parish_staff", "records_clerk", "finance_staff", "parishioner"), r.role_id'
    );
    if (!$statement) {
        return [];
    }
    $statement->bind_param('i', $userId);
    $statement->execute();
    $result = $statement->get_result();
    $roles = [];
    while ($row = $result->fetch_assoc()) {
        $roles[] = $row['role_key'];
    }
    $statement->close();
    return $roles;
}

function recordLoginAttempt(mysqli $conn, ?int $userId, string $identifier, bool $successful, ?string $reason): void {
    $normalized = normalizeAuthenticationIdentifier($identifier);
    $identifierForHash = $normalized['valid'] ? $normalized['type'] . ':' . $normalized['value'] : 'invalid:' . strtolower(trim($identifier));
    $identifierHash = hash('sha256', $identifierForHash);
    $ip = authenticationClientIp();
    $agent = authenticationUserAgent();
    $statement = $conn->prepare(
        'INSERT INTO login_attempts (user_id, identifier_hash, ip_address, was_successful, failure_reason, user_agent) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($statement) {
        $successValue = $successful ? 1 : 0;
        $statement->bind_param('ississ', $userId, $identifierHash, $ip, $successValue, $reason, $agent);
        $statement->execute();
        $statement->close();
    }
}

function loginThrottleState(mysqli $conn, string $identifier): array {
    $normalized = normalizeAuthenticationIdentifier($identifier);
    $hashValue = $normalized['valid'] ? $normalized['type'] . ':' . $normalized['value'] : 'invalid:' . strtolower(trim($identifier));
    $identifierHash = hash('sha256', $hashValue);
    $window = max(60, (int) securitySetting($conn, 'auth.failure_window_seconds', 900));
    $maximum = max(3, (int) securitySetting($conn, 'auth.max_failed_attempts', 5));
    $lockout = max(60, (int) securitySetting($conn, 'auth.lockout_seconds', 900));
    $windowStart = date('Y-m-d H:i:s', time() - $window);

    $statement = $conn->prepare(
        'SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_failure
         FROM login_attempts
         WHERE identifier_hash = ? AND was_successful = 0 AND attempted_at >= ?
           AND attempted_at > COALESCE((
               SELECT MAX(success.attempted_at) FROM login_attempts success
               WHERE success.identifier_hash = ? AND success.was_successful = 1
           ), "1970-01-01 00:00:00")'
    );
    $failures = 0;
    $lastFailure = null;
    if ($statement) {
        $statement->bind_param('sss', $identifierHash, $windowStart, $identifierHash);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $failures = (int) ($row['failures'] ?? 0);
        $lastFailure = $row['last_failure'] ?? null;
        $statement->close();
    }

    $locked = $failures >= $maximum;
    $retryAfter = 0;
    if ($locked && $lastFailure) {
        $retryAfter = max(1, strtotime($lastFailure) + $lockout - time());
        $locked = $retryAfter > 0;
    }
    return ['locked' => $locked, 'retry_after' => $retryAfter, 'failures' => $failures];
}

function applyFailedLoginDelay(mysqli $conn, int $failureCount): void {
    $maximumMs = max(0, (int) securitySetting($conn, 'auth.progressive_delay_max_ms', 2000));
    $delayMs = min($maximumMs, max(0, $failureCount) * 250);
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
}

function administratorMfaIsEnforced(?mysqli $conn = null): bool {
    if ($conn instanceof mysqli) {
        $setting = securitySetting($conn, 'auth.admin_mfa_required');
        if ($setting !== null && ($setting === '0' || $setting === 'false')) {
            return false;
        }
    }
    return defined('ADMIN_MFA_REQUIRED')
        ? ADMIN_MFA_REQUIRED
        : (defined('APP_ENVIRONMENT') && APP_ENVIRONMENT === 'production');
}

function userRequiresLoginMfa(mysqli $conn, array $user, array $roles): bool {
    // Disabled from login flow: credentials-only direct authentication
    return false;
}

function verifiedAuthenticationDestination(mysqli $conn, int $userId, string $type): ?string {
    if (!in_array($type, ['email', 'mobile'], true)) {
        return null;
    }
    $statement = $conn->prepare(
        'SELECT normalized_value FROM user_auth_identifiers
         WHERE user_id = ? AND identifier_type = ? AND verified_at IS NOT NULL LIMIT 1'
    );
    if (!$statement) {
        return null;
    }
    $statement->bind_param('is', $userId, $type);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $row ? (string) $row['normalized_value'] : null;
}

function selectLoginOtpMethod(mysqli $conn, array $user): ?string {
    $userId = (int) ($user['id'] ?? 0);
    $preferred = ($user['verification_method'] ?? 'email') === 'mobile' ? 'mobile' : 'email';
    $hasEmail = verifiedAuthenticationDestination($conn, $userId, 'email') !== null;
    $hasMobile = verifiedAuthenticationDestination($conn, $userId, 'mobile') !== null;
    $smsConfigured = defined('TEXTBEE_API_KEY') && TEXTBEE_API_KEY !== '' && defined('TEXTBEE_DEVICE_ID') && TEXTBEE_DEVICE_ID !== '';

    if ($preferred === 'mobile' && $hasMobile && ($smsConfigured || (defined('APP_ENVIRONMENT') && APP_ENVIRONMENT !== 'production'))) {
        return 'mobile';
    }
    if ($hasEmail) {
        return 'email';
    }
    if ($hasMobile) {
        return 'mobile';
    }
    return null;
}

function establishAuthenticatedSession(mysqli $conn, array $user, bool $mfaVerified = true): void {
    $roles = authenticationRolesForUser($conn, (int) $user['id']);
    if (!$roles) {
        throw new RuntimeException('The account does not have an assigned role.');
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['fullname'] = (string) $user['fullname'];
    $_SESSION['email'] = (string) ($user['email'] ?? '');
    $_SESSION['role_keys'] = $roles;
    $_SESSION['role'] = legacyRoleKey($roles[0]);
    $_SESSION['password_authenticated'] = true;
    $_SESSION['fully_authenticated'] = true;
    $_SESSION['mfa_verified'] = true;
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['session_regenerated_at'] = time();
    $_SESSION['session_fingerprint'] = hash('sha256', authenticationUserAgent());
    unset($_SESSION['pending_auth_transaction']);
}

function beginPasswordAuthentication(mysqli $conn, string $identifier, string $password): array {
    $genericError = 'The credentials provided are invalid.';
    $throttle = loginThrottleState($conn, $identifier);
    if ($throttle['locked']) {
        return ['ok' => false, 'error' => 'Unable to sign in right now. Please wait and try again.', 'retry_after' => $throttle['retry_after']];
    }

    $user = findUserByAuthenticationIdentifier($conn, $identifier);
    $passwordValid = $user && password_verify($password, (string) $user['password']);
    if (!$passwordValid) {
        recordLoginAttempt($conn, $user ? (int) $user['id'] : null, $identifier, false, 'invalid_credentials');
        applyFailedLoginDelay($conn, $throttle['failures'] + 1);
        return ['ok' => false, 'error' => $genericError];
    }

    if (($user['status'] ?? '') !== 'active') {
        recordLoginAttempt($conn, (int) $user['id'], $identifier, false, 'account_unavailable');
        return ['ok' => false, 'error' => $genericError];
    }

    $roles = authenticationRolesForUser($conn, (int) $user['id']);
    if (!$roles) {
        recordLoginAttempt($conn, (int) $user['id'], $identifier, false, 'role_missing');
        return ['ok' => false, 'error' => $genericError];
    }

    recordLoginAttempt($conn, (int) $user['id'], $identifier, true, null);
    session_regenerate_id(true);
    $_SESSION['password_authenticated'] = true;
    $_SESSION['password_authenticated_at'] = time();

    // Direct authentication: no OTP verification step gating dashboard access
    establishAuthenticatedSession($conn, $user, true);
    return ['ok' => true, 'requires_otp' => false, 'user' => $user];
}

function getAuthenticatedUser(mysqli $conn = null): ?array {
    static $cachedUser = false;
    if ($cachedUser !== false) {
        return $cachedUser;
    }
    $conn = $conn instanceof mysqli ? $conn : permissionConnection();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$conn || $userId <= 0 || empty($_SESSION['fully_authenticated'])) {
        return $cachedUser = null;
    }
    $statement = $conn->prepare('SELECT * FROM users WHERE id = ? AND status = "active" LIMIT 1');
    if (!$statement) {
        return $cachedUser = null;
    }
    $statement->bind_param('i', $userId);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;
    $statement->close();
    return $cachedUser = $user;
}

function clearAuthenticationSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function authenticationRequestIsJson(): bool {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $path = str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? ''));
    return strpos($accept, 'application/json') !== false
        || strpos($path, '/api/') !== false
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function denyAuthentication(string $message = 'Authentication is required.', int $status = 401): void {
    http_response_code($status);
    if (authenticationRequestIsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    header('Location: ' . BASE_URL . 'auth/login.php?error=forbidden', true, 302);
    exit;
}

function requireAuthentication(): void {
    $connection = permissionConnection();
    $user = $connection ? getAuthenticatedUser($connection) : null;
    if (!$user) {
        clearAuthenticationSession();
        denyAuthentication();
    }
    if (passwordChangeIsEnforced() && (!empty($user['must_change_password']) || !empty($_SESSION['must_change_password']))) {
        $current = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
        if (!in_array($current, ['change-password.php', 'logout.php'], true)) {
            header('Location: ' . BASE_URL . 'auth/change-password.php?required=1', true, 302);
            exit;
        }
    }
}

function passwordChangeIsEnforced(): bool {
    return defined('APP_ENVIRONMENT') && APP_ENVIRONMENT === 'production';
}

function requireMfa(): void {
    requireAuthentication();
    if (administratorMfaIsEnforced() && in_array('administrator', userRoleKeys(), true) && empty($_SESSION['mfa_verified'])) {
        denyAuthentication('Multi-factor authentication is required.', 403);
    }
}

function requireLogin(): void {
    requireAuthentication();
}

function requireAdmin(): void {
    requireAuthentication();
    requirePermission('admin.access');
}

function requirePermission($permission, $redirect = null): void {
    requireAuthentication();
    if (!hasPermission((string) $permission)) {
        if (authenticationRequestIsJson()) {
            denyAuthentication('You are not authorized to perform this action.', 403);
        }
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['fully_authenticated']);
}

function getUserDashboardURL(): string {
    return hasPermission('admin.access') ? BASE_URL . 'admin/dashboard.php' : BASE_URL . 'users/index.php';
}
