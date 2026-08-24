<?php
/** Environment-aware public URL configuration. */

$appEnvironment = strtolower(trim((string) (getenv('APP_ENV') ?: 'local')));
$configuredUrl = rtrim(trim((string) (getenv('APP_URL') ?: '')), '/');
$configuredPath = trim((string) (getenv('APP_BASE_PATH') ?: ''));

if ($appEnvironment === 'production') {
    if ($configuredUrl === '' || filter_var($configuredUrl, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('APP_URL must be a valid public URL in production.');
    }
    if (strtolower((string) parse_url($configuredUrl, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('APP_URL must use HTTPS in production.');
    }
}

if ($configuredPath === '' && $configuredUrl !== '') {
    $configuredPath = (string) (parse_url($configuredUrl, PHP_URL_PATH) ?: '/');
}
if ($configuredPath === '') {
    $configuredPath = '/ParishSystem/';
}
$configuredPath = '/' . trim($configuredPath, '/') . '/';
if ($configuredPath === '//') {
    $configuredPath = '/';
}

if (!defined('APP_ENVIRONMENT')) {
    define('APP_ENVIRONMENT', $appEnvironment);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', $configuredPath);
}
if (!defined('APP_URL')) {
    define('APP_URL', $configuredUrl);
}

if (!function_exists('appUrl')) {
    function appUrl(string $path = ''): string
    {
        $path = ltrim($path, '/');
        if (APP_URL !== '') {
            return APP_URL . ($path === '' ? '' : '/' . $path);
        }

        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
        $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return ($https ? 'https' : 'http') . '://' . $host . BASE_URL . $path;
    }
}
