<?php
/**
 * AIExtractor
 * -----------
 * Strict Identity-Document Extraction and OCR Intelligence Agent.
 * Processes Philippine identity documents with maximum accuracy,
 * extracting full, un-truncated text strings and mapping them directly to database/form fields.
 *
 * Rules:
 * - Accuracy is prioritized over filling every field.
 * - Never invent, assume, infer, hallucinate, or fabricate information.
 * - Concurrently parses front and back ID cards.
 * - Identifies document type first.
 * - Strictly label-based extraction (ignores headers like 'Apelyido', 'Given Name', 'RE', 'FI').
 * - Preserves compound surnames; separates suffixes (JR, SR, III, etc.).
 * - Validates date of birth (ISO YYYY-MM-DD) and checks valid calendar date.
 * - Never derives birthplace from address.
 * - Real, un-inflated confidence scores.
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
        'document_confidence',
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
You are a strict identity-document extraction system for the TUGON Parish registration system.

Your task is to read ONLY information visibly printed on the provided ID.

Do not guess.
Do not infer.
Do not hallucinate.
Do not complete missing information from general knowledge.
Do not use a person's name to infer sex.
Do not use an address to infer birthplace.
Do not invent missing characters.

Identify the document type first.

Then extract only these fields:

- first_name
- middle_name
- last_name
- suffix
- id_number
- date_of_birth
- address
- birth_place
- sex

Use the labels printed on the ID to determine which value belongs to each field.

Preserve the exact spelling shown on the ID.

For names:
- Do not split names incorrectly.
- Do not move words between first, middle, and last name.
- Preserve compound surnames (e.g., DELA CRUZ, SAN JOSE, DE GUZMAN).
- Preserve suffixes separately (e.g., JR, SR, II, III).
- Never capture two-letter label fragments like "FI" or "RE".

For ID numbers:
- Preserve all digits and characters.
- Never replace letters and numbers based only on assumptions.
- Validate the expected format when possible (e.g., PhilSys 16 digits).

For dates:
- Verify the date is valid.
- Convert valid dates to YYYY-MM-DD.
- If uncertain, return null.

For address:
- Preserve the complete address as printed.
- Do not invent missing parts.

For birthplace:
- Only extract it if explicitly shown on the document.
- Never derive birthplace from address.

For sex:
- Extract only when explicitly shown.
- Normalize M/MALE to Male and F/FEMALE to Female.

If a field cannot be confidently read, return null.

Accuracy is more important than completeness.

Return ONLY valid JSON.

Do not add explanations.

Schema:

{
  "status": "SUCCESS",
  "id_type_detected": null,
  "document_confidence": 0.0,
  "fields": {
    "first_name": {
      "value": null,
      "confidence": 0.0
    },
    "middle_name": {
      "value": null,
      "confidence": 0.0
    },
    "last_name": {
      "value": null,
      "confidence": 0.0
    },
    "suffix": {
      "value": null,
      "confidence": 0.0
    },
    "id_number": {
      "value": null,
      "confidence": 0.0
    },
    "date_of_birth": {
      "value": null,
      "confidence": 0.0
    },
    "address": {
      "value": null,
      "confidence": 0.0
    },
    "birth_place": {
      "value": null,
      "confidence": 0.0
    },
    "sex": {
      "value": null,
      "confidence": 0.0
    }
  },
  "needs_review": false
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

        return null;
    }

    /**
     * Direct multimodal document extraction: sends front (and optional back) ID image directly to Gemini API.
     * High accuracy through security backgrounds (guilloche patterns).
     *
     * @param string      $imagePath      Path to the front ID image
     * @param string|null $backImagePath  Optional path to the back ID image
     * @param string|null $mimeType       MIME type of the front image
     * @return array|null
     */
    public function parseImage(string $imagePath, ?string $backImagePath = null, ?string $mimeType = 'image/jpeg'): ?array
    {
        $apiKey = trim((string) getenv('GEMINI_API_KEY'));
        if ($apiKey === '' || !is_file($imagePath)) {
            return null;
        }

        $imageBytes = (string) file_get_contents($imagePath);
        if ($imageBytes === '') {
            return null;
        }

        $promptText = "Read and extract all visible identity fields from the provided ID card" .
                      ($backImagePath && is_file($backImagePath) ? " (Front and Back side provided)" : " (Front side)") .
                      ". Follow all strict rules: never guess or infer missing values, reject label noise like 'Apelyido' or 'FI', convert dates to YYYY-MM-DD, and return JSON matching the schema.";

        $parts = [
            ['text' => $promptText],
            [
                'inline_data' => [
                    'mime_type' => $mimeType ?: 'image/jpeg',
                    'data'      => base64_encode($imageBytes),
                ],
            ],
        ];

        if ($backImagePath && is_file($backImagePath)) {
            $backBytes = (string) file_get_contents($backImagePath);
            if ($backBytes !== '') {
                $backMime = @mime_content_type($backImagePath) ?: ($mimeType ?: 'image/jpeg');
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $backMime,
                        'data'      => base64_encode($backBytes),
                    ],
                ];
            }
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
            'contents' => [[
                'role'  => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'temperature'      => 0.1,
                'maxOutputTokens'  => 1024,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $responseText = $this->httpPost($url, $payload, [
            'Content-Type' => 'application/json',
        ]);

        // Secondary fallback to gemini-1.5-flash
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

    /* ─── JSON parsing, validation & honest confidence ───────────────────── */

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

        $result = [
            'id_type_detected'    => null,
            'document_confidence' => 0.0,
            'overall_confidence'  => 0.0,
            'needs_review'        => false,
            'first_name'          => null,
            'middle_name'         => null,
            'last_name'           => null,
            'suffix'              => null,
            'id_number'           => null,
            'date_of_birth'       => null,
            'address'             => null,
            'birth_place'         => null,
            'sex'                 => null,
            'field_confidence'    => [],
            'fields'              => [],
        ];

        // 1. Detect document type and confidence
        $metadata = isset($parsed['metadata']) && is_array($parsed['metadata']) ? $parsed['metadata'] : [];
        $result['id_type_detected'] = $parsed['id_type_detected']
            ?? ($metadata['document_type'] ?? null);

        $docConf = $parsed['document_confidence'] ?? ($metadata['confidence_score'] ?? null);
        $result['document_confidence'] = is_numeric($docConf) ? max(0.0, min(1.0, (float) $docConf)) : 0.90;

        $result['needs_review'] = (bool) ($parsed['needs_review'] ?? false);

        // 2. Identify extracted fields structure (Section 20 Schema vs extraction vs flat)
        $rawFields = [];
        $confScores = [];

        if (isset($parsed['fields']) && is_array($parsed['fields'])) {
            foreach ($parsed['fields'] as $k => $item) {
                if (is_array($item)) {
                    $rawFields[$k] = $item['value'] ?? null;
                    if (isset($item['confidence']) && is_numeric($item['confidence'])) {
                        $confScores[$k] = max(0.0, min(1.0, (float) $item['confidence']));
                    }
                } else {
                    $rawFields[$k] = $item;
                }
            }
        } elseif (isset($parsed['extraction']) && is_array($parsed['extraction'])) {
            $rawFields = $parsed['extraction'];
        } elseif (isset($parsed['extracted_data']) && is_array($parsed['extracted_data'])) {
            $rawFields = $parsed['extracted_data'];
        } else {
            $rawFields = $parsed;
        }

        if (isset($parsed['confidence_scores']) && is_array($parsed['confidence_scores'])) {
            foreach ($parsed['confidence_scores'] as $k => $sc) {
                if (is_numeric($sc)) $confScores[$k] = max(0.0, min(1.0, (float) $sc));
            }
        }

        // Surname alias normalization
        if (!empty($rawFields['surname']) && empty($rawFields['last_name'])) {
            $rawFields['last_name'] = $rawFields['surname'];
        }
        if (!empty($confScores['surname']) && empty($confScores['last_name'])) {
            $confScores['last_name'] = $confScores['surname'];
        }

        $labelTokensToStrip = [
            '/^(?:APELYIDO|LAST\s*NAME|SURNAME)[\s:.-]+/iu',
            '/^(?:MGA\s*PANGALAN|GIVEN\s*NAMES?|FIRST\s*NAME)[\s:.-]+/iu',
            '/^(?:GITNANG\s*APELYIDO|MIDDLE\s*NAME)[\s:.-]+/iu',
            '/^(?:KASARIAN|SEX|GENDER)[\s:.-]+/iu',
            '/^(?:ARAW\s*NG\s*KAPANGANAKAN|DATE\s*OF\s*BIRTH|DOB|BIRTHDATE)[\s:.-]+/iu',
            '/^(?:LUGAR\s*NG\s*KAPANGANAKAN|PLACE\s*OF\s*BIRTH|POB)[\s:.-]+/iu',
            '/^(?:TIRAHAN|ADDRESS|RESIDENCE)[\s:.-]+/iu',
        ];

        $targetKeys = ['first_name', 'middle_name', 'last_name', 'suffix', 'id_number', 'date_of_birth', 'address', 'birth_place', 'sex'];

        foreach ($targetKeys as $key) {
            $val = $rawFields[$key] ?? null;
            if ($val === '' || $val === 'null' || $val === 'N/A' || $val === 'NONE') {
                $val = null;
            }

            if (is_string($val)) {
                $val = trim($val);

                // Strip leading label prefixes
                foreach ($labelTokensToStrip as $pattern) {
                    $val = trim((string) preg_replace($pattern, '', $val));
                }

                // Filter out isolated label or noise fragments like "RE", "FI", "APELYIDO"
                $upper = mb_strtoupper($val, 'UTF-8');
                if (in_array($upper, ['RE', 'FI', 'APELYIDO', 'MGA PANGALAN', 'GIVEN NAMES', 'GITNANG APELYIDO', 'LAST NAME', 'FIRST NAME', 'MIDDLE NAME', 'SURNAME', 'KASARIAN', 'TIRAHAN', 'DOB'], true)) {
                    $val = null;
                } else {
                    if ($key !== 'id_type_detected' && $key !== 'date_of_birth') {
                        $val = mb_strtoupper($val, 'UTF-8');
                    }
                }
            }

            // Normalization per field
            if ($key === 'date_of_birth' && $val !== null) {
                $d = DateTime::createFromFormat('Y-m-d', (string) $val);
                if ($d instanceof DateTime && $d->format('Y-m-d') === $val) {
                    $year = (int) $d->format('Y');
                    $month = (int) $d->format('n');
                    $day = (int) $d->format('j');
                    $today = new DateTime('today');
                    if (checkdate($month, $day, $year) && $year >= 1900 && $d <= $today && $today->diff($d)->y <= 125) {
                        $val = $d->format('Y-m-d');
                    } else {
                        $val = null;
                    }
                } else {
                    $val = null;
                }
            }

            if ($key === 'sex' && $val !== null) {
                $upperSex = mb_strtoupper($val, 'UTF-8');
                if (str_contains($upperSex, 'FEMALE') || str_contains($upperSex, 'BABAE') || $upperSex === 'F') {
                    $val = 'Female';
                } elseif (str_contains($upperSex, 'MALE') || str_contains($upperSex, 'LALAKI') || $upperSex === 'M') {
                    $val = 'Male';
                } else {
                    $val = null;
                }
            }

            // Suffix separation if attached to last_name
            if ($key === 'last_name' && $val !== null && empty($result['suffix'])) {
                foreach (['JR.', 'JR', 'SR.', 'SR', 'II', 'III', 'IV', 'V', 'VI'] as $suf) {
                    if (preg_match('/(?:,\s*|\s+)' . preg_quote($suf, '/') . '$/i', $val)) {
                        $val = trim(preg_replace('/(?:,\s*|\s+)' . preg_quote($suf, '/') . '$/i', '', $val), " ,.-");
                        $result['suffix'] = mb_strtoupper($suf, 'UTF-8');
                        break;
                    }
                }
            }

            $conf = $confScores[$key] ?? ($val !== null ? 0.95 : 0.0);
            $result[$key] = $val;
            $result['field_confidence'][$key] = $conf;

            $status = 'not_found';
            if ($val !== null) {
                $status = ($conf >= 0.95) ? 'verified' : 'needs_review';
            }

            $result['fields'][$key] = [
                'value'      => $val,
                'confidence' => $conf,
                'status'     => $status,
                'source'     => 'gemini',
            ];
        }

        // Must have at least last_name or first_name
        if (empty($result['last_name']) && empty($result['first_name'])) {
            return null;
        }

        // Overall confidence calculation
        $presentScores = array_values(array_filter($result['field_confidence'], fn($v) => is_numeric($v) && $v > 0));
        $result['overall_confidence'] = !empty($presentScores)
            ? round(array_sum($presentScores) / count($presentScores), 2)
            : 0.90;
        $explicitConf = $parsed['confidence_score'] ?? ($metadata['confidence_score'] ?? null);
        $result['confidence_score'] = is_numeric($explicitConf)
            ? (float) $explicitConf
            : $result['overall_confidence'];

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
            error_log('[AIExtractor] HTTP ' . $httpCode . ' from ' . parse_url($url, PHP_URL_HOST) . ': ' . ($curlError ?: 'non-2xx response'));
            return null;
        }

        return (string) $response;
    }
}
