<?php
/**
 * Security Configuration & Constants
 * Centralized security settings for the application
 */

require_once __DIR__ . '/app.php';
$isProduction = APP_ENVIRONMENT === 'production';

if (!function_exists('defineSecurityConstant')) {
    function defineSecurityConstant($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

// ===================================================================
// SECURITY SETTINGS
// ===================================================================

// Password Security
defineSecurityConstant('PASSWORD_MIN_LENGTH', max(8, (int) (getenv('PASSWORD_MIN_LENGTH') ?: 8)));
defineSecurityConstant('PASSWORD_REQUIRE_UPPERCASE', true);
defineSecurityConstant('PASSWORD_REQUIRE_NUMBERS', true);
defineSecurityConstant('PASSWORD_REQUIRE_SPECIAL_CHARS', true);
defineSecurityConstant('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
defineSecurityConstant('PASSWORD_HASH_COST', 12); // Higher = more secure but slower
// Session Security
defineSecurityConstant('SESSION_TIMEOUT', 30 * 60); // 30 minutes in seconds
defineSecurityConstant('SESSION_REGENERATE_INTERVAL', 5 * 60); // Regenerate every 5 minutes
defineSecurityConstant('SESSION_COOKIE_HTTPONLY', true);
defineSecurityConstant('SESSION_COOKIE_SECURE', $isProduction);
defineSecurityConstant('SESSION_COOKIE_SAMESITE', 'Lax');
// Keep local development login convenient; production administrators must use MFA.
defineSecurityConstant('ADMIN_MFA_REQUIRED', $isProduction);

// Login Security
defineSecurityConstant('MAX_LOGIN_ATTEMPTS', 5);
defineSecurityConstant('LOGIN_LOCKOUT_DURATION', 15 * 60); // 15 minutes in seconds
defineSecurityConstant('LOGIN_ATTEMPT_WINDOW', 10 * 60); // 10 minute window for counting attempts

// CSRF Protection
defineSecurityConstant('CSRF_TOKEN_EXPIRY', 1 * 3600); // 1 hour
defineSecurityConstant('CSRF_TOKEN_NAME', '_csrf_token');

// Rate Limiting
defineSecurityConstant('RATE_LIMIT_REQUESTS', 100);
defineSecurityConstant('RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// ===================================================================
// DATABASE SECURITY
// ===================================================================

// Prepared Statement Settings
defineSecurityConstant('DB_FETCH_ASSOC', MYSQLI_ASSOC);
defineSecurityConstant('DB_PREPARED_STMT_CACHE_SIZE', 256);

// ===================================================================
// FILE UPLOAD SECURITY
// ===================================================================

defineSecurityConstant('ALLOWED_UPLOAD_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
defineSecurityConstant('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB
defineSecurityConstant('UPLOAD_DIR', __DIR__ . '/../uploads/');

// MIME type whitelist
$ALLOWED_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
];

// ===================================================================
// ENCRYPTION
// ===================================================================

defineSecurityConstant('ENCRYPTION_CIPHER', 'AES-256-CBC');
$encryptionKey = getenv('ENCRYPTION_KEY') ?: '';
if ($isProduction && $encryptionKey === '') {
    throw new RuntimeException('ENCRYPTION_KEY must be configured in production.');
}
defineSecurityConstant('ENCRYPTION_KEY', $encryptionKey !== '' ? $encryptionKey : bin2hex(random_bytes(16)));

// ===================================================================
// CACHE SETTINGS
// ===================================================================

defineSecurityConstant('CACHE_ENABLED', true);
defineSecurityConstant('CACHE_DRIVER', 'file'); // Options: 'file', 'redis', 'memcached'
defineSecurityConstant('CACHE_TTL_DEFAULT', 30 * 60); // 30 minutes
defineSecurityConstant('CACHE_DIR', __DIR__ . '/../cache/');
defineSecurityConstant('CACHE_REDIS_HOST', 'localhost');
defineSecurityConstant('CACHE_REDIS_PORT', 6379);

// ===================================================================
// LOGGING
// ===================================================================

defineSecurityConstant('LOG_ENABLED', true);
defineSecurityConstant('LOG_DIR', __DIR__ . '/../logs/');
defineSecurityConstant('LOG_LEVEL', getenv('LOG_LEVEL') ?: ($isProduction ? 'info' : 'debug'));
defineSecurityConstant('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10 MB per file
defineSecurityConstant('LOG_RETENTION_DAYS', 30);

// ===================================================================
// ERROR HANDLING
// ===================================================================

defineSecurityConstant('DEBUG_MODE', false); // Set to false in production
defineSecurityConstant('DISPLAY_ERRORS', DEBUG_MODE);
defineSecurityConstant('LOG_ERRORS', true);
defineSecurityConstant('ERROR_REPORT_EMAIL', getenv('ERROR_REPORT_EMAIL') ?: '');

// ===================================================================
// API SETTINGS
// ===================================================================

defineSecurityConstant('API_VERSION', 'v1');
defineSecurityConstant('API_RATE_LIMIT', 1000); // Requests per hour
defineSecurityConstant('JWT_EXPIRY', 24 * 3600); // 24 hours
$jwtSecret = getenv('JWT_SECRET_KEY') ?: '';
if ($isProduction && $jwtSecret === '') {
    throw new RuntimeException('JWT_SECRET_KEY must be configured in production.');
}
defineSecurityConstant('JWT_SECRET_KEY', $jwtSecret !== '' ? $jwtSecret : 'local-development-only');
defineSecurityConstant('AI_IDENTITY_API_URL', getenv('AI_IDENTITY_API_URL') ?: '');
defineSecurityConstant('AI_IDENTITY_API_TIMEOUT', 45);

// ===================================================================
// SECURITY HEADERS
// ===================================================================

$SECURITY_HEADERS = [
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'X-XSS-Protection' => '1; mode=block',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(self)',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data: https:; font-src 'self' https://cdnjs.cloudflare.com"
];

// ===================================================================
// PAGINATION SETTINGS
// ===================================================================

defineSecurityConstant('DEFAULT_PAGE_SIZE', 20);
defineSecurityConstant('MAX_PAGE_SIZE', 100);
defineSecurityConstant('PAGINATION_RANGE', 5); // Number of page links to show

// ===================================================================
// EMAIL SETTINGS
// ===================================================================

defineSecurityConstant('SMTP_HOST', getenv('SMTP_HOST') ?: 'localhost');
defineSecurityConstant('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
defineSecurityConstant('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
defineSecurityConstant('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
defineSecurityConstant('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'noreply@parish.local');
defineSecurityConstant('SMTP_FROM_NAME', 'San Lorenzo Ruiz Mission Station');
defineSecurityConstant('SMTP_ENCRYPTION', 'tls'); // 'ssl' or 'tls'

// ===================================================================
// TWILIO SMS SETTINGS
// ===================================================================

defineSecurityConstant('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: '');
defineSecurityConstant('TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: '');
defineSecurityConstant('TWILIO_PHONE_NUMBER', getenv('TWILIO_PHONE_NUMBER') ?: '');

// ===================================================================
// IP WHITELIST / BLACKLIST
// ===================================================================

$IP_WHITELIST = [];
$IP_BLACKLIST = [];

// ===================================================================
// ALLOWED CORS ORIGINS
// ===================================================================

$configuredOrigins = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('ALLOWED_ORIGINS') ?: '')))));
$publicOrigin = APP_URL !== '' ? ((string) parse_url(APP_URL, PHP_URL_SCHEME) . '://' . (string) parse_url(APP_URL, PHP_URL_HOST) . ((int) parse_url(APP_URL, PHP_URL_PORT) > 0 ? ':' . (int) parse_url(APP_URL, PHP_URL_PORT) : '')) : '';
$ALLOWED_ORIGINS = $isProduction
    ? array_values(array_unique(array_filter(array_merge([$publicOrigin], $configuredOrigins))))
    : ['http://localhost', 'http://localhost:3000', 'http://localhost:8000'];

if (!in_array($_SERVER['HTTP_ORIGIN'] ?? '', $ALLOWED_ORIGINS, true)) {
    // Set to empty array in production
}
?>
