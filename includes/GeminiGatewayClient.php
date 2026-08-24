<?php
/**
 * Server-to-server client for Google Gemini AI.
 *
 * Supports both:
 * 1. Dedicated Gemini microservice gateway (GEMINI_GATEWAY_URL)
 * 2. Direct Google Gemini REST API (GEMINI_API_KEY)
 */
class GeminiGatewayClient {
    private $gatewayUrl;
    private $apiKey;
    private $model;
    private $timeout;
    private $lastError = '';
    private $lastErrorCode = '';

    public function __construct($gatewayUrl = null, $apiKey = null, $model = null, $timeout = 60) {
        $this->gatewayUrl = $gatewayUrl ?: (getenv('GEMINI_GATEWAY_URL') ?: '');
        $this->apiKey = $apiKey ?: (getenv('GEMINI_API_KEY') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : ''));
        $this->model = $model ?: (getenv('GEMINI_MODEL') ?: 'gemini-1.5-flash');
        $this->timeout = max(5, intval($timeout));
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getLastErrorCode() {
        return $this->lastErrorCode;
    }

    public function isAvailable() {
        return function_exists('curl_init') && (!empty($this->gatewayUrl) || !empty($this->apiKey));
    }

    public function healthCheck(): array {
        if (!function_exists('curl_init')) {
            return ['online' => false, 'model_available' => false, 'status' => 'offline', 'error' => 'cURL unavailable'];
        }

        if (!empty($this->gatewayUrl)) {
            $healthUrl = str_replace('/api/chat', '/healthz', $this->gatewayUrl);
            $ch = curl_init($healthUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);
            if ($response !== false && $httpCode === 200) {
                $data = json_decode($response, true);
                $configured = is_array($data) && !empty($data['configured']);
                return [
                    'online' => true,
                    'model_available' => $configured,
                    'status' => $configured ? 'online' : 'not_configured',
                    'engine' => 'gemini_gateway'
                ];
            }
        }

        if (!empty($this->apiKey)) {
            return [
                'online' => true,
                'model_available' => true,
                'status' => 'online',
                'engine' => 'gemini_direct'
            ];
        }

        return ['online' => false, 'model_available' => false, 'status' => 'not_configured', 'engine' => 'none'];
    }

    public function chat($message, array $history = []) {
        $this->lastError = '';
        $this->lastErrorCode = '';

        $message = trim((string) $message);
        if ($message === '') {
            $this->lastError = 'No Gemini prompt was supplied.';
            $this->lastErrorCode = 'invalid_request';
            return null;
        }

        // Mode 1: Call Gemini Gateway if configured
        if (!empty($this->gatewayUrl)) {
            $reply = $this->callGateway($message, $history);
            if ($reply !== null) {
                return $reply;
            }
        }

        // Mode 2: Call Direct Google Gemini API if API key is available
        if (!empty($this->apiKey)) {
            return $this->callDirectApi($message, $history);
        }

        $this->lastError = 'Neither GEMINI_GATEWAY_URL nor GEMINI_API_KEY is configured.';
        $this->lastErrorCode = 'not_configured';
        return null;
    }

    private function callGateway(string $message, array $history): ?string {
        $payload = json_encode([
            'message' => $message,
            'history' => array_slice($history, -12),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->gatewayUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->lastError = $curlError ?: 'Unable to reach Gemini gateway.';
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || $httpCode >= 400) {
            $this->lastError = (string) ($data['error'] ?? 'Gemini gateway error.');
            return null;
        }

        return trim((string) ($data['reply'] ?? ''));
    }

    private function callDirectApi(string $message, array $history): ?string {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);

        $contents = [];
        foreach (array_slice($history, -8) as $h) {
            $role = ($h['role'] ?? '') === 'assistant' ? 'model' : 'user';
            $text = trim((string) ($h['content'] ?? ''));
            if ($text !== '') {
                $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
            }
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $payload = json_encode(['contents' => $contents], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->lastError = $curlError ?: 'Unable to reach Google Gemini API.';
            return null;
        }

        $data = json_decode($response, true);
        if ($httpCode >= 400 || !is_array($data)) {
            $this->lastError = (string) ($data['error']['message'] ?? 'Gemini API call failed.');
            return null;
        }

        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return trim((string) $reply);
    }
}

