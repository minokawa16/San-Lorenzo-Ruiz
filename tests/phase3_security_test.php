<?php
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$failed = 0;
function securityCheck(bool $ok, string $label): void {
    global $failed;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}

$token = generateCsrfToken();
securityCheck(is_string($token) && strlen($token) === 64, 'CSRF tokens are cryptographically sized');
securityCheck(verifyCsrfToken($token), 'valid session CSRF token accepted');
securityCheck(!verifyCsrfToken(str_repeat('0', 64)), 'invalid CSRF token rejected');
securityCheck(!secureValidateUpload(['error' => UPLOAD_ERR_NO_FILE], ['max_size' => 100, 'extensions' => ['pdf'], 'mime_types' => ['application/pdf' => 'pdf']])['ok'], 'failed upload rejected');
securityCheck(is_file(dirname(__DIR__) . '/includes/security-middleware.php'), 'central security middleware exists');
securityCheck(strpos(file_get_contents(dirname(__DIR__) . '/api/calendar-events.php'), 'requireValidCsrfToken') !== false, 'calendar JSON mutations enforce CSRF');
securityCheck(strpos(file_get_contents(dirname(__DIR__) . '/api/ai-assistant.php'), 'requireValidCsrfToken') !== false, 'AI JSON mutations enforce CSRF');
$logoutSource = file_get_contents(dirname(__DIR__) . '/auth/logout.php');
securityCheck(strpos($logoutSource, "includes/session.php") !== false && preg_match('/\bsession_start\s*\(/', $logoutSource) !== 1, 'logout destroys the centralized TUGON session');
$adminSidebar = file_get_contents(dirname(__DIR__) . '/includes/admin-sidebar.php');
$userSidebar = file_get_contents(dirname(__DIR__) . '/includes/user-sidebar.php');
securityCheck(
    strpos($adminSidebar, 'admin/ai-assistant.php') === false
        && strpos($adminSidebar, 'admin/chatbot-knowledge.php') === false
        && strpos($userSidebar, 'users/ai-assistant.php') !== false,
    'AI navigation is available only in the parishioner sidebar'
);
echo "{$failed} failed security checks." . PHP_EOL;
exit($failed ? 1 : 0);
