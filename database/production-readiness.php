<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$staticOnly = in_array('--static', $argv, true);
$failures = [];
$warnings = [];
$passes = [];
$root = dirname(__DIR__);

$pass = static function (string $message) use (&$passes): void { $passes[] = $message; };
$fail = static function (string $message) use (&$failures): void { $failures[] = $message; };
$warn = static function (string $message) use (&$warnings): void { $warnings[] = $message; };
$env = static fn(string $primary, string $alias = ''): string => trim((string) (getenv($primary) ?: ($alias !== '' ? getenv($alias) : '')));

$requiredFiles = [
    'docker/tugon-apache.conf', 'docker/tugon-production.ini', 'docker/worker.sh',
    'healthz.php', 'database/migrate.php', 'database/run-notification-deliveries.php',
    'database/run-reservation-reminders.php', 'database/run-announcement-lifecycle.php',
    'database/canonical-migrations/011_ai_reports_audit_performance.sql',
    'database/canonical-migrations/012_auth_password_column_protection.sql',
];
foreach ($requiredFiles as $file) {
    is_file($root . '/' . $file) ? $pass("Required artifact present: {$file}") : $fail("Missing required artifact: {$file}");
}

$dockerIgnore = (string) @file_get_contents($root . '/.dockerignore');
foreach (['vendor', 'storage', 'uploads', 'config/db.local.php', '.env'] as $privatePath) {
    str_contains($dockerIgnore, $privatePath) ? $pass("Build context excludes {$privatePath}") : $fail("Build context must exclude {$privatePath}");
}
$apache = (string) @file_get_contents($root . '/docker/tugon-apache.conf');
str_contains($apache, 'LocationMatch') && str_contains($apache, 'uploads|storage|backups|logs')
    ? $pass('Apache denies direct access to private runtime paths')
    : $fail('Apache private-path deny rules are incomplete');

if (!$staticOnly) {
    $environment = strtolower($env('APP_ENV'));
    $environment === 'production' ? $pass('APP_ENV is production') : $fail('APP_ENV must equal production');

    $appUrl = $env('APP_URL');
    filter_var($appUrl, FILTER_VALIDATE_URL) && strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) === 'https'
        ? $pass('APP_URL is a valid HTTPS URL')
        : $fail('APP_URL must be the public HTTPS URL');

    foreach ([
        'DB_HOST' => $env('DB_HOST', 'MYSQLHOST'),
        'DB_USER' => $env('DB_USER', 'MYSQLUSER'),
        'DB_PASSWORD' => $env('DB_PASSWORD', 'MYSQLPASSWORD'),
        'DB_NAME' => $env('DB_NAME', 'MYSQLDATABASE'),
    ] as $name => $value) {
        $value !== '' ? $pass("{$name} is configured") : $fail("{$name} is required");
    }
    if (strtolower($env('DB_USER', 'MYSQLUSER')) === 'root') {
        $fail('Production must use a least-privilege database user, not root');
    }

    foreach (['ENCRYPTION_KEY', 'JWT_SECRET_KEY'] as $secretName) {
        strlen($env($secretName)) >= 32
            ? $pass("{$secretName} has an acceptable length")
            : $fail("{$secretName} must contain at least 32 random characters");
    }
    ((int) ($env('PASSWORD_MIN_LENGTH') ?: 12)) >= 12
        ? $pass('Production password minimum is at least 12 characters')
        : $fail('PASSWORD_MIN_LENGTH must be at least 12 in production');

    $mailEnabled = strtolower($env('MAIL_ENABLED')) !== 'false';
    $mailUser = $env('MAIL_USERNAME') ?: $env('GMAIL_USER');
    $mailPassword = $env('MAIL_PASSWORD') ?: $env('GMAIL_APP_PASSWORD');
    $mailHost = $env('MAIL_HOST') ?: ($env('GMAIL_USER') !== '' ? 'smtp.gmail.com' : '');
    if (!$mailEnabled || $mailHost === '' || $mailUser === '' || $mailPassword === '') {
        $fail('SMTP credentials are required because administrator login uses email MFA');
    } else {
        $pass('SMTP configuration is present for administrator MFA');
    }

    $host = $env('DB_HOST', 'MYSQLHOST');
    $user = $env('DB_USER', 'MYSQLUSER');
    $password = $env('DB_PASSWORD', 'MYSQLPASSWORD');
    $name = $env('DB_NAME', 'MYSQLDATABASE');
    $port = (int) ($env('DB_PORT', 'MYSQLPORT') ?: 3306);
    if ($host !== '' && $user !== '' && $name !== '') {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($host, $user, $password, $name, $port);
        if ($db->connect_errno) {
            $fail('Production database connection failed');
        } else {
            $pass('Production database connection succeeded');
            $migration = $db->query("SELECT filename FROM schema_migrations WHERE filename='012_auth_password_column_protection.sql' LIMIT 1");
            $migration && $migration->num_rows === 1
                ? $pass('Canonical migrations through 012 are applied')
                : $fail('Canonical migration 012 is not applied');
            $admins = $db->query("SELECT email,must_change_password FROM users WHERE status='active' AND id IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE r.role_key='administrator')");
            if (!$admins || $admins->num_rows < 1) {
                $fail('No active RBAC administrator exists');
            } else {
                while ($admin = $admins->fetch_assoc()) {
                    if (in_array(strtolower((string) $admin['email']), ['admin@gmail.com', 'admin@parish.com'], true)) {
                        $fail('A legacy default administrator account is active');
                    }
                    if ((int) $admin['must_change_password'] === 1) {
                        $warn('An administrator must change the temporary password before public launch');
                    }
                }
                $pass('At least one active RBAC administrator exists');
            }
            $db->close();
        }
    }

    $dataRoot = $env('TUGON_DATA_DIR') ?: ($env('RAILWAY_VOLUME_MOUNT_PATH') ?: $root);
    is_dir($dataRoot) && is_writable($dataRoot)
        ? $pass('Persistent data root exists and is writable')
        : $fail('TUGON_DATA_DIR/RAILWAY volume must exist and be writable');
}

foreach ($passes as $message) {
    echo "PASS  {$message}\n";
}
foreach ($warnings as $message) {
    echo "WARN  {$message}\n";
}
foreach ($failures as $message) {
    echo "FAIL  {$message}\n";
}
echo sprintf("RESULT pass=%d warn=%d fail=%d\n", count($passes), count($warnings), count($failures));
exit($failures === [] ? 0 : 1);
