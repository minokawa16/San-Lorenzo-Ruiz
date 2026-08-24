<?php

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function phase1Assert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$message}\n";
}

function phase1Contents(string $path): string
{
    $contents = file_get_contents($path);
    return $contents === false ? '' : $contents;
}

$requiredFiles = [
    'database/migrate.php',
    'database/canonical-migrations/000_schema_baseline.sql',
    'database/canonical-migrations/001_utf8mb4_and_communion_typo.sql',
    'database/canonical-migrations/002_complete_first_communion_officials.sql',
    'includes/schema.php',
    'includes/permissions.php',
    'includes/validation.php',
    'includes/uploads.php',
    'includes/notifications.php',
    'includes/audit.php',
    'includes/errors.php',
    'controllers/MyRequestsController.php',
    'services/RequestListService.php',
    'repositories/RequestRepository.php',
    'views/users/my-requests.php',
];
foreach ($requiredFiles as $relativePath) {
    phase1Assert(is_file($root . '/' . $relativePath), "required Phase 1 file exists: {$relativePath}");
}

$config = phase1Contents($root . '/database/config.php');
phase1Assert(strpos($config, "set_charset('utf8mb4')") !== false, 'database connections explicitly use utf8mb4');

$entryPoint = phase1Contents($root . '/users/my-requests.php');
phase1Assert(strpos($entryPoint, 'MyRequestsController') !== false, 'My Requests uses the controller architecture');
phase1Assert(strpos($entryPoint, "views/users/my-requests.php") !== false, 'My Requests delegates presentation to a view');

$legacyCertificateRoutes = [
    'add_request_files.php', 'downloads.php', 'replace_file.php', 'request_form.php',
    'request_handler.php', 'request_submitted.php', 'request_view_user.php',
    'submit_requirements.php', 'submit_success.php', 'upload_handler.php',
];
foreach ($legacyCertificateRoutes as $file) {
    $contents = phase1Contents($root . '/certs/' . $file);
    phase1Assert(strpos($contents, "_retired.php") !== false, "legacy certificate route is retired: certs/{$file}");
}

$canonicalRedirects = [
    'admin/dashboard-redesigned.php' => 'dashboard.php',
    'users/dashboard.php' => 'index.php',
    'auth/login_secure.php' => 'login.php',
];
foreach ($canonicalRedirects as $relativePath => $target) {
    phase1Assert(
        strpos(phase1Contents($root . '/' . $relativePath), $target) !== false,
        "duplicate route redirects to canonical target: {$relativePath}"
    );
}

$runtimeDdlViolations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $fileInfo) {
    $path = $fileInfo->getPathname();
    $normalized = str_replace('\\', '/', $path);
    if ($fileInfo->getExtension() !== 'php'
        || strpos($normalized, '/vendor/') !== false
        || strpos($normalized, '/tests/') !== false
        || substr($normalized, -strlen('/database/migrate.php')) === '/database/migrate.php'
        || substr($normalized, -strlen('/admin/settings.php')) === '/admin/settings.php'
        || substr($normalized, -strlen('/includes/BackupManager.php')) === '/includes/BackupManager.php') {
        continue;
    }
    $contents = phase1Contents($path);
    if (preg_match('/(?:->query|->multi_query|mysqli_query|->prepare)\s*\(\s*[\'\"]\s*(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\s+TABLE\b/i', $contents)) {
        $runtimeDdlViolations[] = substr($normalized, strlen(str_replace('\\', '/', $root)) + 1);
    }
}
phase1Assert(!$runtimeDdlViolations, 'browser-facing PHP contains no executable table DDL');
if ($runtimeDdlViolations) {
    echo '      ' . implode(', ', $runtimeDdlViolations) . "\n";
}

$permissions = phase1Contents($root . '/includes/permissions.php');
phase1Assert(strpos($permissions, "'admin' => ['*']") !== false, 'canonical permission policy preserves administrator access');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
