<?php

if (function_exists('tugonLoadEnvFile')) {
    tugonLoadEnvFile();
} elseif (file_exists(__DIR__ . '/../includes/helpers.php')) {
    require_once __DIR__ . '/../includes/helpers.php';
    if (function_exists('tugonLoadEnvFile')) {
        tugonLoadEnvFile();
    }
}

if (!defined('TEXTBEE_API_KEY')) {
    define('TEXTBEE_API_KEY', getenv('TEXTBEE_API_KEY') ?: '');
}

if (!defined('TEXTBEE_DEVICE_ID')) {
    define('TEXTBEE_DEVICE_ID', getenv('TEXTBEE_DEVICE_ID') ?: '');
}

if (!defined('TEXTBEE_BASE_URL')) {
    define('TEXTBEE_BASE_URL', rtrim(getenv('TEXTBEE_BASE_URL') ?: 'https://api.textbee.dev/api/v1', '/'));
}
