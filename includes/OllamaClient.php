<?php
/**
 * Offline Ollama client for local Llama 3.2 generation.
 */

class OllamaClient {
    private $chatUrl;
    private $healthUrl;
    private $model;
    private $timeout;
    private $temperature;
    private $numPredict;
    private $lastError = '';
    private $lastErrorCode = '';

    public function __construct() {
        require_once __DIR__ . '/../config/ollama.php';

        $this->chatUrl = OLLAMA_CHAT_URL;
        $this->healthUrl = OLLAMA_HEALTH_URL;
        $this->model = OLLAMA_MODEL;
        $this->timeout = OLLAMA_TIMEOUT;
        $this->temperature = OLLAMA_TEMPERATURE;
        $this->numPredict = OLLAMA_NUM_PREDICT;
    }

    public function isAvailable() {
        return function_exists('curl_init');
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getLastErrorCode() {
        return $this->lastErrorCode;
    }

    public function getModel() {
        return $this->model;
    }

    private function request($url, $method = 'GET', array $payload = null, $timeout = null) {
        $this->lastError = '';
        $this->lastErrorCode = '';

        if (!$this->isAvailable()) {
            $this->lastError = 'PHP cURL is unavailable.';
            $this->lastErrorCode = 'curl_unavailable';
            return null;
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if (strtoupper($method) === 'POST') {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $this->lastError = 'Unable to encode the Ollama request.';
                $this->lastErrorCode = 'invalid_request';
                return null;
            }
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($response === false) {
            $this->lastError = $curlError ?: 'Unable to connect to Ollama.';
            $this->lastErrorCode = $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->lastError = 'Ollama returned invalid JSON.';
            $this->lastErrorCode = 'invalid_response';
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = (string) ($data['error'] ?? ('Ollama HTTP error ' . $httpCode));
            $this->lastErrorCode = stripos($this->lastError, 'model') !== false || stripos($this->lastError, 'not found') !== false
                ? 'model_unavailable'
                : 'server_error';
            return null;
        }

        return $data;
    }

    public function healthCheck() {
        $data = $this->request($this->healthUrl, 'GET', null, 8);
        if ($data === null) {
            return [
                'online' => false,
                'model_available' => false,
                'error_code' => $this->lastErrorCode ?: 'unavailable',
            ];
        }

        $configured = strtolower($this->model);
        $modelAvailable = false;
        foreach (($data['models'] ?? []) as $model) {
            $name = strtolower((string) ($model['name'] ?? $model['model'] ?? ''));
            $baseName = explode(':', $name, 2)[0];
            $configuredBase = explode(':', $configured, 2)[0];
            if ($name === $configured || $baseName === $configuredBase) {
                $modelAvailable = true;
                break;
            }
        }

        return [
            'online' => true,
            'model_available' => $modelAvailable,
            'error_code' => $modelAvailable ? '' : 'model_unavailable',
        ];
    }

    public function chat(array $messages) {
        $cleanMessages = [];
        foreach (array_slice($messages, -1 * OLLAMA_MAX_HISTORY_MESSAGES - 2) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));
            if (!in_array($role, ['system', 'user', 'assistant'], true) || $content === '') {
                continue;
            }
            $cleanMessages[] = ['role' => $role, 'content' => $content];
        }
        if (!$cleanMessages) {
            $this->lastError = 'No valid chat messages were supplied.';
            $this->lastErrorCode = 'invalid_request';
            return null;
        }

        $data = $this->request($this->chatUrl, 'POST', [
            'model' => $this->model,
            'messages' => $cleanMessages,
            'stream' => false,
            'options' => [
                'temperature' => $this->temperature,
                'num_predict' => $this->numPredict,
            ],
        ]);
        if ($data === null) {
            return null;
        }

        $text = trim((string) ($data['message']['content'] ?? ''));
        if ($text === '') {
            $this->lastError = 'Ollama returned an empty chat response.';
            $this->lastErrorCode = 'empty_response';
            return null;
        }
        return $text;
    }

    public function generate($prompt) {
        return $this->chat([
            ['role' => 'user', 'content' => (string) $prompt],
        ]);
    }
}
?>
