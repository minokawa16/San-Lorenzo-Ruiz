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

function writeAuditLog(
    mysqli $conn, $userId, string $action, ?string $tableName = null, $recordId = null,
    $oldValue = null, $newValue = null, string $component = 'application',
    ?string $event = null, ?string $correlationId = null
): bool {
    $actor = $userId !== null && (int)$userId > 0 ? (int)$userId : null;
    $record = $recordId !== null && (int)$recordId > 0 ? (int)$recordId : null;
    $oldJson = tugonAuditJson($oldValue);
    $newJson = tugonAuditJson($newValue);
    $ip = function_exists('tugonClientIp') ? tugonClientIp() : mb_strimwidth((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45, '');
    $correlationId = $correlationId ?: tugonCorrelationId();
    $event = $event ?: strtolower(preg_replace('/[^a-z0-9]+/i', '.', $action));
    $agentHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    $stmt = $conn->prepare('INSERT INTO audit_log
        (user_id,action,table_name,record_id,old_value,new_value,ip_address,correlation_id,component,event,user_agent_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    if (!$stmt) return false;
    $stmt->bind_param('ississsssss', $actor, $action, $tableName, $record, $oldJson, $newJson, $ip, $correlationId, $component, $event, $agentHash);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
