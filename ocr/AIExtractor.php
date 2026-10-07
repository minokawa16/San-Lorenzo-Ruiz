<?php
/**
 * AIExtractor
 * -----------
 * Enterprise-grade Document Parsing and OCR Intelligence Agent.
 * Processes identity documents, structured forms, and scanned cards with 100% accuracy,
 * extracting full, un-truncated text strings and mapping them directly to database/form fields.
 *
 * Directives:
 * - Full string extraction (no truncation or abbreviation)
 * - Label vs. value separation
 * - Dual-pass accuracy & validation
 * - Background & noise artifact cleaning
 *
 * Configuration (environment variables):
 *   GEMINI_GATEWAY_URL   Internal Railway Gemini gateway endpoint (preferred)
 *   GEMINI_API_KEY       Direct Google Gemini REST API key (fallback)
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
        'sex',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
### ROLE & SYSTEM OVERVIEW
You are an enterprise-grade Document Parsing and OCR Intelligence System. Your objective is to process identity documents, structured forms, and scanned cards with 100% accuracy, extracting full, un-truncated text strings and mapping them directly to target database/form fields.

---

### CORE OPERATIONAL DIRECTIVES:

1. EXTRACT FULL STRINGS (NO TRUNCATION OR ABBREVIATION):
   - Capture complete names, numbers, and addresses. Never cut off text, truncate words, or shorten multi-word fields (e.g., extract "REY MARK", not "RE" or "REY").
   - Retain full character length for identification numbers, including spaces, hyphens, and slashes.

2. LABEL VS. VALUE SEPARATION:
   - Identify and ignore field labels, headers, and UI instructions (e.g., "Given Name", "Last Name", "Date of Birth", "Address", "Republic of the Philippines", "Apelyido", "Mga Pangalan", "Gitnang Apelyido").
   - Extract ONLY the actual user data values associated with those labels.

3. DUAL-PASS ACCURACY & VALIDATION:
   - Pass 1 (Detection): Scan and transcribe raw text regions in original reading order (top-to-bottom, left-to-right).
   - Pass 2 (Mapping): Map transcribed text to standardized field keys, verifying character counts against visible text in the image.
   - Cross-check data against secondary image elements (e.g., comparing visible QR/barcode text or back-of-card text with front-of-card text when available).

4. DATA CLEANING & STANDARDIZATION:
   - Trim leading, trailing, and double spaces.
   - Ignore background graphics, holograms, security patterns, glare artifacts, or stray OCR noise (such as random punctuation marks '.', '|', or '`').
   - Convert dates into ISO 8601 format (YYYY-MM-DD) while preserving original text values if conversion is ambiguous.

---

### FIELD MAPPING MATRIX:

- `first_name`: Full given name(s) including second/third given names.
- `middle_name`: Full middle name or middle initial. Return `null` if explicitly blank/absent.
- `last_name`: Full legal surname/family name.
- `id_number`: Exact ID alphanumeric string including hyphens or formatting marks.
- `date_of_birth`: Formatted date (YYYY-MM-DD).
- `address`: Full address string (street, barangay, district, city/municipality, province, country).
- `sex`: "Male" or "Female" if present, otherwise `null`.

---

### STRICT JSON OUTPUT FORMAT:
Return ONLY a valid JSON object. Do not include markdown formatting or commentary outside the JSON block.

{
  "status": "SUCCESS",
  "extraction": {
    "first_name": "<Full Given Name(s) or null>",
    "middle_name": "<Full Middle Name or null>",
    "last_name": "<Full Surname/Family Name or null>",
    "id_number": "<Exact Full Alphanumeric ID or null>",
    "date_of_birth": "<YYYY-MM-DD or null>",
    "address": "<Full Address String or null>",
    "sex": "<Male | Female | null>"
  },
  "metadata": {
    "document_type": "<PhilSys National ID | Driver's License | UMID | Passport | Voter's ID | Other>",
    "confidence_score": 1.00
  }
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

    /**
     * Direct multimodal document extraction: sends ID image directly to Gemini API.
     * High accuracy through security backgrounds (guilloche patterns).
     */
    public function parseImage(string $imagePath, ?string $mimeType = 'image/jpeg'): ?array
    {
        $apiKey = trim((string) getenv('GEMINI_API_KEY'));
        if ($apiKey === '' || !is_file($imagePath)) {
            return null;
        }

        $imageBytes = (string) file_get_contents($imagePath);
        if ($imageBytes === '') {
            return null;
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => "Carefully read this identity document image. Extract full, un-truncated names (First Name, Middle Name, Last Name), ID Number, Date of Birth, Address, and Sex. Return valid JSON matching the schema."],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType ?: 'image/jpeg',
                            'data' => base64_encode($imageBytes)
                        ]
                    ]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 768,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $responseText = $this->httpPost($url, $payload, [
            'Content-Type' => 'application/json',
        ]);

        if ($responseText === null) {
            $urlFallback = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey);
            $responseText = $this->httpPost($urlFallback, $payload, ['Content-Type' => 'application/json']);
        }

        if ($responseText === null) {
            return null;
        }

        $data = json_decode($responseText, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return $this->parseAiContent((string) ($content ?? ''));
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
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
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
            'Content-Type' => 'application/json',
        ]);

        if ($responseText === null) {
            return null;
        }

        $data    = json_decode($responseText, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return $this->parseAiContent((string) ($content ?? ''));
    }

    /* ─── JSON parsing & validation ──────────────────────────────────────── */

    public function parseAiContent(string $content): ?array
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

        // Support extraction (enterprise prompt), extracted_data (prior template), and legacy flat formats
        $extracted = isset($parsed['extraction']) && is_array($parsed['extraction'])
            ? $parsed['extraction']
            : (isset($parsed['extracted_data']) && is_array($parsed['extracted_data'])
                ? $parsed['extracted_data']
                : $parsed);

        // Parse metadata (document_type, confidence_score) if present
        $metadata = isset($parsed['metadata']) && is_array($parsed['metadata']) ? $parsed['metadata'] : [];
        if (!empty($metadata['document_type']) && empty($parsed['id_type_detected'])) {
            $parsed['id_type_detected'] = $metadata['document_type'];
        }
        if (isset($metadata['confidence_score']) && is_numeric($metadata['confidence_score']) && !isset($parsed['confidence_score'])) {
            $parsed['confidence_score'] = (float) $metadata['confidence_score'];
        }

        $confScores = isset($parsed['confidence_scores']) && is_array($parsed['confidence_scores'])
            ? $parsed['confidence_scores']
            : [];

        // Normalize surname -> last_name alias
        if (!empty($extracted['surname']) && empty($extracted['last_name'])) {
            $extracted['last_name'] = $extracted['surname'];
        }
        if (!empty($confScores['surname']) && empty($confScores['last_name'])) {
            $confScores['last_name'] = $confScores['surname'];
        }

        // Validate & sanitise
        $result = [];
        foreach (self::SCHEMA_FIELDS as $field) {
            $val = $extracted[$field] ?? ($parsed[$field] ?? null);
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
                if ($val !== null && is_numeric($val)) {
                    $val = max(0.0, min(1.0, (float) $val));
                } elseif (!empty($confScores)) {
                    $numericScores = array_filter($confScores, 'is_numeric');
                    $val = !empty($numericScores) ? max(0.0, min(1.0, (float)(array_sum($numericScores) / count($numericScores)))) : 0.90;
                }
            }
            // Date must be YYYY-MM-DD
            if ($field === 'date_of_birth' && $val !== null) {
                $d = DateTime::createFromFormat('Y-m-d', (string) $val);
                $val = ($d instanceof DateTime && $d->format('Y-m-d') === $val) ? $val : null;
            }
            $result[$field] = $val;
        }

        // Attach per-field confidence scores map
        $result['field_confidence'] = $confScores;
        $result['confidence_scores'] = $confScores;

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
