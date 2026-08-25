<?php
/**
 * AIExtractor
 * -----------
 * Sends raw Tesseract / cloud OCR text to the Railway-hosted Gemini
 * gateway (or direct Gemini REST) and asks it to clean OCR noise,
 * identify the ID type, and return a structured JSON object.
 *
 * Usage:
 *   $extractor = new AIExtractor();
 *   $result    = $extractor->parse($rawOcrText);
 *   // $result is null on failure/no AI key, or:
 *   // [
 *   //   'id_type_detected' => 'PhilSys National ID',
 *   //   'confidence_score' => 0.92,
 *   //   'first_name'       => 'JUAN',
 *   //   'middle_name'      => 'S',
 *   //   'last_name'        => 'DELA CRUZ',
 *   //   'suffix'           => null,
 *   //   'id_number'        => '1234-5678-9012-3456',
 *   //   'date_of_birth'    => '1998-05-14',
 *   //   'address'          => 'Poblacion 1, Aleosan, Cotabato',
 *   //   'birth_place'      => 'Aleosan, Cotabato',
 *   // ]
 *
 * Configuration (environment variables):
 *   GEMINI_GATEWAY_URL  Internal Railway Gemini gateway endpoint (preferred)
 *   GEMINI_API_KEY      Direct Google Gemini REST API key (fallback)
 *   AI_EXTRACTOR_TIMEOUT (optional, seconds, default 18)
 */

declare(strict_types=1);

class AIExtractor
{
    private const SCHEMA_FIELDS = [
        'id_type_detected',
        'confidence_score',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'id_number',
        'date_of_birth',
        'address',
        'birth_place',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert e-KYC document parser specialised in Philippine government-issued IDs.

Given raw, noisy OCR text extracted from a scanned Philippine ID, your task is to:
1. Identify the type of ID (PhilSys National ID / ePhilID, Driver's License, UMID, SSS, PRC, Voter's ID, or Philippine Passport).
2. Correct common OCR character misreads (e.g. 0↔O, 1↔I, rn↔m, clumped words, broken letters).
3. Split the full name correctly into first_name, middle_name, last_name, and suffix.
4. Normalise the date of birth to YYYY-MM-DD format.
5. Normalise the address (Street, Barangay, Municipality/City, Province).
6. Return ONLY a valid JSON object — no markdown fences, no explanation text, no trailing commas.

Output schema (use null for any field you cannot determine with confidence):
{
  "id_type_detected":  "<string>",
  "confidence_score":  <float 0.0–1.0>,
  "first_name":        "<string or null>",
  "middle_name":       "<string or null>",
  "last_name":         "<string or null>",
  "suffix":            "<string or null>",
  "id_number":         "<string or null>",
  "date_of_birth":     "<YYYY-MM-DD or null>",
  "address":           "<string or null>",
  "birth_place":       "<string or null>"
}
PROMPT;

    private int $timeout;

    public function __construct(int $timeoutSeconds = 18)
    {
        $envTimeout = (int) getenv('AI_EXTRACTOR_TIMEOUT');
        $this->timeout = $envTimeout > 0 ? $envTimeout : $timeoutSeconds;
    }

    /**
     * Parse raw OCR text using an AI model.
     *
     * @param  string $rawOcrText  Raw text from Tesseract / OCR.space
     * @return array|null          Structured array or null if AI unavailable / failed
     */
    public function parse(string $rawOcrText): ?array
    {
        $rawOcrText = trim($rawOcrText);
        if ($rawOcrText === '') {
            return null;
        }

        // ── 1. Try Railway internal Gemini gateway ──────────────────────────
        $gatewayUrl = trim((string) getenv('GEMINI_GATEWAY_URL'));
        if ($gatewayUrl !== '' && str_contains($gatewayUrl, 'railway')) {
            $result = $this->callGeminiGateway($gatewayUrl, $rawOcrText);
            if ($result !== null) {
                return $result;
            }
        }

        // ── 2. Try direct Google Gemini REST API ────────────────────────────
        $apiKey = trim((string) getenv('GEMINI_API_KEY'));
        if ($apiKey !== '') {
            $result = $this->callGeminiRest($apiKey, $rawOcrText);
            if ($result !== null) {
                return $result;
            }
        }

        // No AI backend available — caller falls back to regex-parsed result
        return null;
    }

    /* ─── Railway Gemini Gateway ─────────────────────────────────────────── */

    /**
     * Calls the internal Railway Gemini gateway which uses the OpenAI-compatible
     * chat completion API format (used by the TUGON chatbot).
     */
    private function callGeminiGateway(string $url, string $ocrText): ?array
    {
        $payload = json_encode([
            'model'    => 'gemini-2.0-flash',
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => "Parse this raw ID OCR text:\n\n" . $ocrText],
            ],
            'temperature' => 0.1,
            'max_tokens'  => 512,
        ]);

        $responseText = $this->httpPost($url, $payload, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        if ($responseText === null) {
            return null;
        }

        // Gateway returns OpenAI-compatible response
        $data = json_decode($responseText, true);
        $content = $data['choices'][0]['message']['content']
                ?? $data['message']['content']
                ?? $data['content']
                ?? null;

        return $this->parseAiContent((string) ($content ?? ''));
    }

    /* ─── Direct Google Gemini REST API ──────────────────────────────────── */

    private function callGeminiRest(string $apiKey, string $ocrText): ?array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => "Parse this raw ID OCR text:\n\n" . $ocrText]],
            ]],
            'generationConfig' => [
                'temperature'     => 0.1,
                'maxOutputTokens' => 512,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $responseText = $this->httpPost($url, $payload, [
            'Content-Type: application/json',
        ]);

        if ($responseText === null) {
            return null;
        }

        $data    = json_decode($responseText, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return $this->parseAiContent((string) ($content ?? ''));
    }

    /* ─── JSON parsing & validation ──────────────────────────────────────── */

    private function parseAiContent(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // Strip markdown code fences if the model wrapped the JSON
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        // Extract first JSON object from the string
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0; $inStr = false; $escaped = false; $end = -1;
        for ($i = $start; $i < strlen($content); $i++) {
            $c = $content[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($c === '\\') { $escaped = $inStr; continue; }
            if ($c === '"') { $inStr = !$inStr; continue; }
            if ($inStr) continue;
            if ($c === '{') $depth++;
            elseif ($c === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        $json = $end >= 0 ? substr($content, $start, $end - $start + 1) : substr($content, $start);

        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            return null;
        }

        // Validate & sanitise
        $result = [];
        foreach (self::SCHEMA_FIELDS as $field) {
            $val = $parsed[$field] ?? null;
            if ($val === '' || $val === 'null') {
                $val = null;
            }
            // Normalise strings
            if (is_string($val)) {
                $val = trim($val);
                if ($field !== 'id_type_detected' && $field !== 'date_of_birth') {
                    $val = mb_strtoupper($val, 'UTF-8');
                }
            }
            // Confidence must be a float 0–1
            if ($field === 'confidence_score') {
                $val = is_numeric($val) ? max(0.0, min(1.0, (float) $val)) : null;
            }
            // Date must be YYYY-MM-DD
            if ($field === 'date_of_birth' && $val !== null) {
                $d = DateTime::createFromFormat('Y-m-d', (string) $val);
                $val = ($d instanceof DateTime && $d->format('Y-m-d') === $val) ? $val : null;
            }
            $result[$field] = $val;
        }

        // Must have at least last_name or first_name to be useful
        if (empty($result['last_name']) && empty($result['first_name'])) {
            return null;
        }

        return $result;
    }

    /* ─── HTTP helper ────────────────────────────────────────────────────── */

    private function httpPost(string $url, string $body, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR    => false,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
            // Soft failure — let caller fall through to next backend
            error_log('[AIExtractor] HTTP ' . $httpCode . ' from ' . parse_url($url, PHP_URL_HOST) . ': ' . ($curlError ?: 'non-2xx response'));
            return null;
        }

        return (string) $response;
    }
}
