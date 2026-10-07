<?php
/**
 * AIExtractor
 * -----------
 * High-precision Optical Character Recognition (OCR) and Information Extraction
 * Agent specializing in government-issued identification cards.
 *
 * Enforces zero-hallucination, 100% accurate data extraction, strict field mapping,
 * noise artifact stripping, and per-field confidence scoring.
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
### ROLE & SYSTEM INSTRUCTIONS
You are a high-precision Optical Character Recognition (OCR) and Information Extraction Agent specializing in government-issued identification cards, particularly Philippine government IDs (PhilSys National ID / ePhilID, Driver's License, UMID, SSS, PRC, Voter's ID, Philippine Passport). Your primary mandate is zero-hallucination, 100% accurate data extraction and field mapping.

---

### EXTRACTION RULES & STRICT CONSTRAINTS:

1. ABSOLUTE ACCURACY & ZERO HALLUCINATION:
   - Extract text EXACTLY as printed on the provided ID card. 
   - Never guess, infer, spell-correct, or complete partial names/words.
   - If a character or word is blurry, obscured, or illegible, set the confidence score below 0.5 or return null rather than guessing.

2. FIELD-LEVEL MAPPING REQUIREMENTS:
   - First Name (Mga Pangalan / Given Names): Extract exact given name(s). Do not include middle names here.
   - Surname / Last Name (Apelyido / Last Name): Extract exact legal family name/surname.
   - Middle Name (Gitnang Apelyido / Middle Name): Extract full middle name or initial exactly as shown. Return null if absent.
   - ID Number: Extract full alphanumeric ID string including hyphens, spaces, or slashes exactly as formatted on the document (e.g. PhilSys Card Number XXXX-XXXX-XXXX-XXXX).
   - Address (Tirahan): Extract complete street, barangay/district, municipality/city, province, and postal code in exact original sequence.
   - Date of Birth (Petsa ng Kapanganakan): Normalize to YYYY-MM-DD format (convert Tagalog or English months like "DECEMBER 16, 2005" or "16 DISYEMBRE 2005" to 2005-12-16) or null if illegible.
   - Sex (Kasarian): Extract "Male" or "Female" if present, otherwise null.

3. DATA NORMALIZATION & CLEANING:
   - Remove spurious background noise, guilloche security patterns, glare artifacts, or OCR-generated stray symbols (e.g., random dots, pipes '|', or quotes).
   - Standardize letter casing (UPPERCASE) while preserving exact character spelling and accents (e.g., Ñ).
   - Remove accidental leading or trailing whitespace.

4. CONFIDENCE VERIFICATION & FALLBACK:
   - Cross-check extracted text between Front ID and Back ID (if applicable, e.g., QR code / barcode data) to confirm consistency.
   - Output structured JSON containing the mapped form fields along with a confidence score (0.0 to 1.0) for each field.

---

### OUTPUT FORMAT (JSON):
Return ONLY a valid JSON object matching this schema — no markdown fences, no explanation text:
{
  "status": "SUCCESS",
  "id_type_detected": "<PhilSys National ID | Driver's License | UMID | Passport | Voter's ID | Other>",
  "extracted_data": {
    "first_name": "<Extracted First Name>",
    "middle_name": "<Extracted Middle Name or null>",
    "surname": "<Extracted Last Name>",
    "id_number": "<Extracted ID Number>",
    "address": "<Extracted Address or null>",
    "date_of_birth": "<YYYY-MM-DD or null>",
    "sex": "<Male | Female | null>"
  },
  "confidence_scores": {
    "first_name": 1.0,
    "middle_name": 1.0,
    "surname": 1.0,
    "id_number": 1.0,
    "address": 1.0,
    "date_of_birth": 1.0,
    "sex": 1.0
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
                    ['text' => "Carefully read this government-issued ID image. Extract Last Name (Apelyido), Given Name(s) (Mga Pangalan), Middle Name (Gitnang Apelyido), Date of Birth (Petsa ng Kapanganakan), Address (Tirahan), and ID Number / PhilSys Card Number. Return valid JSON matching the schema."],
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

        // Support both structured template (extracted_data + confidence_scores) and legacy flat format
        $extracted = isset($parsed['extracted_data']) && is_array($parsed['extracted_data'])
            ? $parsed['extracted_data']
            : $parsed;

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
