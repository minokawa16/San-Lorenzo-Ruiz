<?php

/** Central browser security middleware. Must run before page output. */
function applySecurityHeaders(): void {
    if (headers_sent()) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src 'self' data: https:; font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Resolve the browser IP without trusting arbitrary forwarded headers. */
function tugonClientIp(): string {
    $remote = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'unknown';

    // Vercel controls X-Forwarded-For on external rewrites. Its first address
    // is the browser; Railway's edge may append another proxy hop.
    if (!empty($_SERVER['HTTP_X_VERCEL_ID']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) return substr($candidate, 0, 45);
        }
    }

    // Direct Railway traffic receives an edge-controlled X-Real-IP header.
    if (!empty($_SERVER['HTTP_X_RAILWAY_EDGE'])) {
        $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if (filter_var($real, FILTER_VALIDATE_IP)) return substr($real, 0, 45);
    }

    return substr((string) $remote, 0, 45);
}

function securityErrorId(): string {
    return 'ERR-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function safeErrorResponse(string $message = 'The operation could not be completed. Please try again later.', int $status = 500): void {
    $id = securityErrorId();
    $logger = class_exists('Logger') ? new Logger() : null;
    if ($logger) $logger->error($message, ['error_id' => $id, 'endpoint' => $_SERVER['REQUEST_URI'] ?? '']);
    http_response_code($status);
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'INTERNAL_ERROR', 'message' => $message . ' Error ID: ' . $id]);
    } else {
        echo e($message . ' Error ID: ' . $id);
    }
    exit;
}
