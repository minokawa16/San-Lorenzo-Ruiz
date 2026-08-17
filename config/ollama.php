<?php
/**
 * Offline Ollama AI Configuration
 *
 * Install Ollama locally and run:
 *   ollama pull llama3.2
 *   ollama serve
 */

if (!function_exists('defineOllamaConstant')) {
    function defineOllamaConstant($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

if (function_exists('tugonLoadEnvFile')) {
    tugonLoadEnvFile();
}

$ollamaBaseUrl = rtrim((string) (getenv('OLLAMA_BASE_URL') ?: 'http://localhost:11434'), '/');
defineOllamaConstant('OLLAMA_BASE_URL', $ollamaBaseUrl);
defineOllamaConstant('OLLAMA_CHAT_URL', getenv('OLLAMA_CHAT_URL') ?: $ollamaBaseUrl . '/api/chat');
defineOllamaConstant('OLLAMA_HEALTH_URL', getenv('OLLAMA_HEALTH_URL') ?: $ollamaBaseUrl . '/api/tags');
defineOllamaConstant('OLLAMA_MODEL', getenv('OLLAMA_MODEL') ?: 'llama3.2');
defineOllamaConstant('OLLAMA_TIMEOUT', max(10, intval(getenv('OLLAMA_TIMEOUT') ?: 120)));
defineOllamaConstant('OLLAMA_TEMPERATURE', floatval(getenv('OLLAMA_TEMPERATURE') ?: 0.2));
defineOllamaConstant('OLLAMA_NUM_PREDICT', intval(getenv('OLLAMA_NUM_PREDICT') ?: 700));
defineOllamaConstant('OLLAMA_MAX_HISTORY_MESSAGES', max(2, intval(getenv('OLLAMA_MAX_HISTORY_MESSAGES') ?: 8)));
?>
