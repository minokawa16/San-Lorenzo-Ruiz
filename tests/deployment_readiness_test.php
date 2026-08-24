<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$docker = $read('Dockerfile');
$apache = $read('docker/tugon-apache.conf');
$ignore = $read('.dockerignore');
$ocr = $read('ocr/health.php');
$entrypoint = $read('docker/entrypoint.sh');
$worker = $read('docker/worker.sh');
$deliveryRunner = $read('database/run-notification-deliveries.php');
$environment = $read('.env.example');
$security = $read('config/security.php');
$setup = $read('database/setup.sql');
$passwordRepair = $read('fix-password.sql');
$requestWorkflow = $read('admin/request-workflow.php');
$settings = $read('admin/settings.php');
$registration = $read('auth/register.php');
$faceVerification = $read('assets/js/face-verification.js');
$middleware = $read('includes/security-middleware.php');

$check(str_contains($docker, 'FROM composer:2 AS dependencies') && str_contains($docker, '--classmap-authoritative'), 'reproducible Composer production dependencies');
$check(str_contains($docker, 'a2enconf tugon-security') && str_contains($docker, 'HEALTHCHECK'), 'Apache security configuration and healthcheck enabled');
$check(str_contains($apache, 'Require all denied') && str_contains($apache, 'uploads|storage|backups|logs'), 'private runtime directories denied at Apache layer');
$check(str_contains($apache, 'vendor|docker|tools'), 'implementation directories denied at Apache layer');
$check(str_contains($ignore, "\nuploads\n") && str_contains($ignore, "\nstorage\n") && str_contains($ignore, "\nvendor\n"), 'private/runtime artifacts excluded from image context');
$check(str_contains($ignore, '!database/canonical-migrations/*.sql'), 'canonical migrations retained in image');
$check(str_contains($ocr, "hasPermission('admin.access')") && str_contains($ocr, "'unauthorized'"), 'OCR diagnostics require administrator authentication');
$check(str_contains($entrypoint, 'production-readiness.php --startup') && str_contains($entrypoint, 'TUGON_RUN_MIGRATIONS'), 'startup preflight and controlled migrations wired');
$check(str_contains($entrypoint, 'TUGON_RUN_EMBEDDED_WORKER') && str_contains($entrypoint, 'gosu www-data tugon-worker'), 'single-service testing worker mode is available');
$check(str_contains($worker, 'run-notification-deliveries.php') && str_contains($worker, 'run-reservation-reminders.php') && str_contains($worker, 'run-announcement-lifecycle.php'), 'background worker covers all scheduled tasks');
$check(str_contains($deliveryRunner, "GET_LOCK('tugon_notification_delivery_worker'") && str_contains($deliveryRunner, 'RELEASE_LOCK'), 'notification worker prevents concurrent duplicate runs');
$check(str_contains($environment, 'APP_URL=https://') && str_contains($environment, 'TUGON_DATA_DIR=') && str_contains($environment, 'MAIL_ENABLED=true'), 'production environment template is complete');
$check(str_contains($security, '$isProduction ? 12 : 8'), 'production password minimum is hardened');
$check(str_contains($security, "defineSecurityConstant('ADMIN_MFA_REQUIRED', \$isProduction)"), 'administrator MFA remains mandatory in production');
$check(!str_contains($setup, 'admin123') && !str_contains($setup, "admin@gmail.com"), 'legacy setup no longer provisions a default administrator');
$check(!str_contains($passwordRepair, '$2y$'), 'fixed password repair hash removed');
$check(str_contains($requestWorkflow, 'requireValidCsrfToken()') && substr_count($requestWorkflow, 'csrfInput()') >= 3, 'admin request workflow enforces CSRF');
$check(str_contains($settings, 'requireValidCsrfToken()') && substr_count($settings, 'csrfInput()') >= 8, 'administrator settings and recovery forms enforce CSRF');
$check(str_contains($registration, "\$face_status = 'admin_review'") && str_contains($registration, "?, ?, ?, NULL)"), 'registration never trusts browser face-match status');
$check(!str_contains($faceVerification, "'/ParishSystem/models'") && str_contains($faceVerification, "new URL('../../models'"), 'face model URL follows the deployed base path');
$check(str_contains($middleware, 'HTTP_X_VERCEL_ID') && str_contains($middleware, 'HTTP_X_RAILWAY_EDGE') && str_contains($middleware, 'HTTP_X_REAL_IP'), 'trusted deployment proxy headers preserve client IP throttling');

echo "RESULT pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
