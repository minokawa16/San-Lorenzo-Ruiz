<?php
/**
 * Server-to-server client for the local Node.js Gemini gateway.
 *
 * The browser never calls this class or receives the Gemini API key. The key
 * remains inside server/.env and is read only by the Node process.
 */
class GeminiGatewayClient {
    private $endpoint;
    private $timeout;
    private $lastError = '';
    private $lastErrorCode = '';

    public function __construct($endpoint = null, $timeout = 70) {
        $configuredEndpoint = getenv('GEMINI_GATEWAY_URL');
        $this->endpoint = $endpoint ?: ($configuredEndpoint ?: 'http://127.0.0.1:3001/api/chat');
        $this->timeout = max(10, intval($timeout));
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function getLastErrorCode() {
        return $this->lastErrorCode;
    }

    public function isAvailable() {
        return function_exists('curl_init');
    }

    public function chat($message, array $history = []) {
        $this->lastError = '';
        $this->lastErrorCode = '';

        if (!$this->isAvailable()) {
            $this->lastError = 'PHP cURL is unavailable.';
            $this->lastErrorCode = 'curl_unavailable';
            return null;
        }

        $message = trim((string) $message);
        if ($message === '') {
            $this->lastError = 'No Gemini prompt was supplied.';
            $this->lastErrorCode = 'invalid_request';
            return null;
        }

        $payload = json_encode([
            'message' => $message,
            'history' => array_slice($history, -12),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $this->lastError = 'Unable to encode the Gemini gateway request.';
            $this->lastErrorCode = 'invalid_request';
            return null;
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($response === false) {
            $this->lastError = $curlError ?: 'Unable to reach the local Gemini gateway.';
            $this->lastErrorCode = $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'gateway_unavailable';
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->lastError = 'The Gemini gateway returned invalid JSON.';
            $this->lastErrorCode = 'invalid_response';
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = trim((string) ($data['error'] ?? 'Gemini request failed.'));
            if ($httpCode === 429) {
                $this->lastErrorCode = 'rate_limited';
            } elseif ($httpCode === 503) {
                $this->lastErrorCode = 'not_configured';
            } elseif ($httpCode === 401 || $httpCode === 403) {
                $this->lastErrorCode = 'invalid_key';
            } else {
                $this->lastErrorCode = 'gateway_error';
            }
            return null;
        }

        $reply = trim((string) ($data['reply'] ?? ''));
        if ($reply === '') {
            $this->lastError = 'Gemini returned an empty response.';
            $this->lastErrorCode = 'empty_response';
            return null;
        }

        return $reply;
    }
}
?>

