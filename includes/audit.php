<?php

/** Canonical audit and request-correlation helpers. */
function tugonCorrelationId(): string
{
    static $id;
    if ($id !== null) return $id;
    $incoming = trim((string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
    if (preg_match('/^[a-f0-9-]{16,64}$/i', $incoming)) return $id = strtolower($incoming);
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return $id = sprintf('%s-%s-%s-%s-%s', substr($hex,0,8), substr($hex,8,4), substr($hex,12,4), substr($hex,16,4), substr($hex,20));
}

function tugonRedactSensitive($value)
{
    $sensitiveKeys = '/pass(word)?|otp|token|secret|session|cookie|authorization|api[_-]?key|card|cvv|receipt|document/i';
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) $clean[$key] = preg_match($sensitiveKeys, (string)$key) ? '[REDACTED]' : tugonRedactSensitive($item);
        return $clean;
    }
    if (is_object($value)) return tugonRedactSensitive((array)$value);
    if (!is_string($value)) return $value;
    $value = preg_replace('/\b\d{6}\b/', '[REDACTED_OTP]', $value);
    $value = preg_replace('/(bearer\s+)[a-z0-9._~+\/-]+/i', '$1[REDACTED]', $value);
    $value = preg_replace('/([?&](?:token|secret|key)=)[^&\s]+/i', '$1[REDACTED]', $value);
    $value = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[REDACTED_PAYMENT]', $value);
    return mb_strimwidth($value, 0, 4000, '...');
}

function tugonAuditJson($value): ?string
{
    if ($value === null) return null;
    $json = json_encode(tugonRedactSensitive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? null : $json;
}

/** Resolves client IP with reverse-proxy and edge-network fallbacks. */
function resolveAuditClientIp(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cf = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cf, FILTER_VALIDATE_IP)) return substr($cf, 0, 45);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $candidates = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($candidates as $cand) {
            $cand = trim($cand);
            if (filter_var($cand, FILTER_VALIDATE_IP)) return substr($cand, 0, 45);
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $real = trim((string)$_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($real, FILTER_VALIDATE_IP)) return substr($real, 0, 45);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $remote = trim((string)$_SERVER['REMOTE_ADDR']);
        if (filter_var($remote, FILTER_VALIDATE_IP)) return substr($remote, 0, 45);
    }
    if (function_exists('tugonClientIp')) {
        $tip = tugonClientIp();
        if ($tip !== 'unknown' && filter_var($tip, FILTER_VALIDATE_IP)) return substr($tip, 0, 45);
    }
    return PHP_SAPI === 'cli' ? '127.0.0.1 (CLI)' : '127.0.0.1';
}

/** Lightweight device & browser parser for audit log rendering. */
function parseUserAgentSummary(?string $userAgent): array
{
    $ua = trim((string)($userAgent ?? ''));
    if ($ua === '' || $ua === 'unknown') {
        return ['device' => 'Desktop', 'browser' => 'Browser', 'icon' => 'fa-desktop'];
    }
    if (stripos($ua, 'CLI') !== false || stripos($ua, 'Worker') !== false) {
        return ['device' => 'System', 'browser' => 'CLI / Cron', 'icon' => 'fa-terminal'];
    }

    $device = 'Desktop';
    $icon = 'fa-desktop';
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
        $device = 'Tablet';
        $icon = 'fa-tablet-screen-button';
    } elseif (preg_match('/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/i', $ua)) {
        $device = 'Mobile';
        $icon = 'fa-mobile-screen-button';
    }

    $browser = 'Browser';
    if (preg_match('/Edg[ea]?\/([0-9.]+)/i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Chrome\/([0-9.]+)/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari\/([0-9.]+)/i', $ua) && !preg_match('/Chrome/i', $ua)) {
        $browser = 'Safari';
    } elseif (preg_match('/MSIE|Trident/i', $ua)) {
        $browser = 'IE';
    } elseif (preg_match('/curl|python|postman|insomnia|wget/i', $ua)) {
        $browser = 'API Client';
        $device = 'Bot/Tool';
        $icon = 'fa-robot';
    }

    return ['device' => $device, 'browser' => $browser, 'icon' => $icon];
}

/** Look up actor snapshot (name & role) with static caching. */
function resolveAuditActor(mysqli $conn, ?int $userId): array
{
    static $cache = [];
    if ($userId === null || $userId <= 0) {
        return ['name' => 'Unknown / Visitor', 'role' => 'guest'];
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
        $name = !empty($_SESSION['fullname']) ? (string)$_SESSION['fullname'] : ('User #' . $userId);
        $role = !empty($_SESSION['role']) ? (string)$_SESSION['role'] : 'user';
        return $cache[$userId] = ['name' => $name, 'role' => $role];
    }
    $stmt = $conn->prepare('SELECT fullname, role FROM users WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $cache[$userId] = [
                'name' => !empty($row['fullname']) ? (string)$row['fullname'] : ('User #' . $userId),
                'role' => !empty($row['role']) ? (string)$row['role'] : 'user'
            ];
        }
    }
    return $cache[$userId] = ['name' => 'User #' . $userId, 'role' => 'user'];
}

/** Inactive/active event categorization helper. */
function inferAuditEventCategory(string $action, string $component, ?string $table): string
{
    $act = strtoupper($action);
    $comp = strtolower($component);
    $tbl = strtolower((string)$table);

    if (
        str_contains($act, 'LOGIN') || str_contains($act, 'LOGOUT') ||
        str_contains($act, 'PASSWORD') || str_contains($act, 'OTP') ||
        str_contains($act, 'MFA') || $comp === 'authentication' || $comp === 'auth'
    ) {
        return 'AUTH';
    }

    if (
        str_contains($tbl, 'baptism') || str_contains($tbl, 'marriage') ||
        str_contains($tbl, 'funeral') || str_contains($tbl, 'communion') ||
        str_contains($tbl, 'confirmation') || str_contains($tbl, 'sacramental') ||
        str_contains($act, 'SACRAMENTAL')
    ) {
        return 'SACRAMENTS';
    }

    if (
        str_contains($tbl, 'request') || str_contains($tbl, 'reservation') ||
        str_contains($tbl, 'schedule') || str_contains($tbl, 'proposal') ||
        str_contains($act, 'REQUEST') || str_contains($act, 'RESERVATION') ||
        str_contains($act, 'SCHEDULE')
    ) {
        return 'REQUESTS';
    }

    if (
        str_contains($tbl, 'user') || str_contains($tbl, 'profile') ||
        str_contains($act, 'REGISTRATION') || str_contains($act, 'PROFILE') ||
        str_contains($act, 'ACCOUNT')
    ) {
        return 'ACCOUNTS';
    }

    if (
        str_contains($tbl, 'cert') || str_contains($act, 'CERT') ||
        $comp === 'certificates'
    ) {
        return 'CERTIFICATES';
    }

    return 'SYSTEM';
}

/** Inactive/active event severity helper. */
function inferAuditEventSeverity(string $action, string $category): string
{
    $act = strtoupper($action);
    if (
        str_contains($act, 'ARCHIVE') || str_contains($act, 'DELETE') ||
        str_contains($act, 'PURGE') || str_contains($act, 'DESTROY') ||
        str_contains($act, 'DROP') || str_contains($act, 'LOCK') ||
        str_contains($act, 'SECURITY') || str_contains($act, 'ROLE_CHANGE')
    ) {
        return 'CRITICAL';
    }

    if (
        str_contains($act, 'FAIL') || str_contains($act, 'FAILURE') ||
        str_contains($act, 'REJECT') || str_contains($act, 'CONFLICT') ||
        str_contains($act, 'DENIED') || str_contains($act, 'CANCEL') ||
        str_contains($act, 'REVOKE') || str_contains($act, 'INVALID') ||
        str_contains($act, 'BLOCKED')
    ) {
        return 'WARNING';
    }

    return 'INFO';
}

/** Human-readable event description generator. */
function generateAuditDescription(
    string $action, ?string $tableName, ?int $recordId,
    string $actorName, string $actorRole, ?string $ip
): string {
    $act = strtoupper($action);
    $table = $tableName ?: 'system';
    $target = $recordId ? "#{$recordId}" : '';

    switch ($act) {
        case 'LOGIN':
            return "{$actorName} ({$actorRole}) logged into the portal successfully.";
        case 'LOGIN_FAILURE':
            return "Failed login attempt from IP {$ip}.";
        case 'LOGOUT':
            return "{$actorName} logged out of the portal.";
        case 'APPROVED_SACRAMENTAL_REQUEST':
        case 'APPROVE_SACRAMENTAL_REQUEST':
            return "Approved sacramental request {$target}; synchronized official registry and calendar schedule.";
        case 'COMPLETED_SACRAMENTAL_REQUEST':
            return "Completed sacramental request {$target}.";
        case 'REJECT_REQUEST':
        case 'REJECTED_SACRAMENTAL_REQUEST':
            return "Rejected request {$target}.";
        case 'CREATE_REQUEST':
            return "Submitted new request {$target} under {$table}.";
        case 'UPDATE_REQUEST':
            return "Updated request details for {$target}.";
        case 'ARCHIVE_SACRAMENTAL_RECORD':
            return "Archived official sacramental record {$target} from registry book {$table}.";
        case 'RESTORE_SACRAMENTAL_RECORD':
            return "Restored sacramental record {$target} in {$table}.";
        case 'GENERATE_CERTIFICATE':
            return "Generated official sacramental certificate for record {$target}.";
        case 'DOWNLOAD_CERTIFICATE':
            return "Downloaded issued sacramental certificate {$target}.";
        case 'DOWNLOAD_REQUEST_DOCUMENT':
            return "Downloaded attached request verification document {$target}.";
        case 'APPROVE_REGISTRATION':
            return "Approved parishioner registration {$target} and activated account.";
        case 'REJECT_REGISTRATION':
            return "Rejected parishioner registration {$target}.";
        case 'REGISTRATION_PENDING_VERIFICATION':
            return "New parishioner registration submitted {$target}; pending admin verification.";
        case 'CHANGE_PASSWORD':
            return "Password successfully updated for user account {$target}.";
        case 'RESET_PASSWORD_OTP':
            return "Password reset completed via OTP verification.";
        case 'SYNC_REQUEST_CALENDAR':
            return "Synchronized parish schedule event {$target} linked to sacramental request.";
        case 'SYNC_RESERVATION_CALENDAR':
            return "Synchronized reservation calendar event {$target}.";
        case 'EXPORT_AUDIT_LOG':
            return "Exported audit compliance log records.";
        case 'EXPORT_REPORT':
            return "Generated and exported administrative statistical report.";
        default:
            $prettyAction = ucwords(strtolower(str_replace('_', ' ', $act)));
            $prettyTable = ucwords(strtolower(str_replace('_', ' ', $table)));
            return "{$prettyAction} performed on {$prettyTable}" . ($recordId ? " #{$recordId}" : "") . ".";
    }
}

/**
 * Write a canonical audit trail record with full security & compliance metadata.
 */
function writeAuditLog(
    mysqli $conn, $userId, string $action, ?string $tableName = null, $recordId = null,
    $oldValue = null, $newValue = null, string $component = 'application',
    ?string $event = null, ?string $correlationId = null,
    ?string $description = null, ?string $eventCategory = null, ?string $severity = null,
    ?string $targetType = null, ?int $targetId = null
): bool {
    $actorId = $userId !== null && (int)$userId > 0 ? (int)$userId : null;
    $record = $recordId !== null && (int)$recordId > 0 ? (int)$recordId : null;
    $tId = $targetId !== null && (int)$targetId > 0 ? (int)$targetId : $record;
    $tType = $targetType ?: ($tableName ?: 'system');

    $actorInfo = resolveAuditActor($conn, $actorId);
    $actorName = $actorInfo['name'];
    $actorRole = $actorInfo['role'];

    $oldJson = tugonAuditJson($oldValue);
    $newJson = tugonAuditJson($newValue);

    $ip = resolveAuditClientIp();
    $userAgent = mb_strimwidth((string)($_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'PHP CLI / System Worker' : 'unknown')), 0, 500, '');
    $agentHash = hash('sha256', $userAgent);
    $correlationId = $correlationId ?: tugonCorrelationId();
    $event = $event ?: strtolower(preg_replace('/[^a-z0-9]+/i', '.', $action));

    $category = $eventCategory ?: inferAuditEventCategory($action, $component, $tableName);
    $validCategories = ['AUTH', 'SACRAMENTS', 'REQUESTS', 'ACCOUNTS', 'CERTIFICATES', 'SYSTEM'];
    if (!in_array($category, $validCategories, true)) {
        $category = 'SYSTEM';
    }

    $sev = $severity ?: inferAuditEventSeverity($action, $category);
    $validSeverities = ['INFO', 'WARNING', 'CRITICAL'];
    if (!in_array($sev, $validSeverities, true)) {
        $sev = 'INFO';
    }

    $desc = $description ?: generateAuditDescription($action, $tableName, $record, $actorName, $actorRole, $ip);

    $sql = 'INSERT INTO audit_log
        (user_id, user_name, user_role, action, severity, target_type, target_id, description,
         old_values, new_values, table_name, record_id, old_value, new_value,
         ip_address, user_agent, event_category, correlation_id, component, event, user_agent_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Audit log prepare failed: ' . $conn->error);
        return false;
    }

    $stmt->bind_param(
        'isssssissssssssssssss',
        $actorId,
        $actorName,
        $actorRole,
        $action,
        $sev,
        $tType,
        $tId,
        $desc,
        $oldJson,
        $newJson,
        $tableName,
        $record,
        $oldJson,
        $newJson,
        $ip,
        $userAgent,
        $category,
        $correlationId,
        $component,
        $event,
        $agentHash
    );

    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Audit log execute failed: ' . $stmt->error);
    }
    $stmt->close();
    return $ok;
}
