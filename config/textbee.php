<?php
/**
 * TextBee SMS Gateway Configuration
 * https://textbee.dev
 */

if (function_exists('tugonLoadEnvFile')) {
    tugonLoadEnvFile();
} else {
    $envPath = dirname(__DIR__) . '/.env';
    if (is_file($envPath) && is_readable($envPath)) {
        $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($envLines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
    }
}

function getTextBeeEnv(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false && trim((string)$val) !== '') {
        return trim((string)$val);
    }
    if (!empty($_ENV[$key])) {
        return trim((string)$_ENV[$key]);
    }
    if (!empty($_SERVER[$key])) {
        return trim((string)$_SERVER[$key]);
    }
    return $default;
}

if (!defined('TEXTBEE_API_KEY')) {
    define('TEXTBEE_API_KEY', getTextBeeEnv('TEXTBEE_API_KEY'));
}

if (!defined('TEXTBEE_DEVICE_ID')) {
    define('TEXTBEE_DEVICE_ID', getTextBeeEnv('TEXTBEE_DEVICE_ID'));
}

if (!defined('TEXTBEE_BASE_URL')) {
    define('TEXTBEE_BASE_URL', rtrim(getTextBeeEnv('TEXTBEE_BASE_URL', 'https://api.textbee.dev/api/v1'), '/'));
}

/**
 * Returns configuration health and credentials status.
 */
function isTextBeeConfigured(): bool {
    return defined('TEXTBEE_API_KEY') && TEXTBEE_API_KEY !== ''
        && defined('TEXTBEE_DEVICE_ID') && TEXTBEE_DEVICE_ID !== '';
}

function getTextBeeConfig(): array {
    $key = defined('TEXTBEE_API_KEY') ? TEXTBEE_API_KEY : '';
    $deviceId = defined('TEXTBEE_DEVICE_ID') ? TEXTBEE_DEVICE_ID : '';
    $baseUrl = defined('TEXTBEE_BASE_URL') ? TEXTBEE_BASE_URL : 'https://api.textbee.dev/api/v1';

    $maskedKey = ($key !== '')
        ? (substr($key, 0, 7) . '...' . substr($key, -4))
        : '(empty)';

    return [
        'configured' => isTextBeeConfigured(),
        'api_key_masked' => $maskedKey,
        'has_api_key' => $key !== '',
        'device_id' => $deviceId,
        'has_device_id' => $deviceId !== '',
        'base_url' => $baseUrl
    ];
}
