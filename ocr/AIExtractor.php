<?php
declare(strict_types=1);

/**
 * AIExtractor
 * -----------
 * Multimodal AI identity extraction engine for Philippine Government IDs.
 * Strictly maps Gemini response schema (surname, given_names, document_number, etc.)
 * directly to application database and registration form fields.
 */
class AIExtractor
{
    private int $timeout;

    public function __construct(int $timeoutSeconds = 25)
    {
        $this->timeout = $timeoutSeconds;
    }

    private function getApiKey(): string
    {
        if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
            return (string) GEMINI_API_KEY;
        }
        return trim((string) (getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '')));
    }

    public function parseImage(string $imagePath, ?string $backImagePath = null, ?string $mimeType = 'image/jpeg'): ?array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '' || !is_file($imagePath)) {
            return null;
        }

        $frontBytes = (string) file_get_contents($imagePath);
        if ($frontBytes === '') {
            return null;
        }

        $parts = [
            [
                'text' => "You are an expert OCR & identity verification engine for Philippine Government IDs (PhilSys National ID, Driver's License, UMID).
Extract identity information with 100% precision and return a valid JSON object matching this exact structure:
{
  \"type\": \"national_id\",
  \"country_code\": \"PHL\",
  \"nationality\": \"Filipino\",
  \"document_number\": \"<16-digit PCN number like 6573-2841-6302-1426>\",
  \"surname\": \"<Full Last Name>\",
  \"given_names\": \"<Full First Name>\",
  \"middle_name\": \"<Full Middle Name>\",
  \"sex\": \"<Male | Female>\",
  \"date_of_birth\": \"<YYYY-MM-DD>\",
  \"place_of_birth\": \"<Place of birth or null>\",
  \"address\": \"<Full Address String>\",
  \"issuing_authority\": \"Philippine Statistics Authority\"
}"
            ],
            [
                'inline_data' => [
                    'mime_type' => $mimeType ?: 'image/jpeg',
                    'data'      => base64_encode($frontBytes),
                ],
            ],
        ];

        if ($backImagePath && is_file($backImagePath)) {
            $backBytes = (string) file_get_contents($backImagePath);
            if ($backBytes !== '') {
                $parts[] = ['text' => 'Back side of the ID card:'];
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType ?: 'image/jpeg',
                        'data'      => base64_encode($backBytes),
                    ],
                ];
            }
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        $payload = json_encode([
            'contents'         => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature'      => 0.0,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $data = json_decode((string) $response, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$content) {
            return null;
        }

        return $this->parseAiContent((string) $content);
    }

    /**
     * Parses raw JSON text returned by Gemini or test mocks into standard application fields.
     */
    public function parseAiContent(string $rawJson): ?array
    {
        $cleanJson = trim($rawJson);
        if (str_starts_with($cleanJson, '```')) {
            $cleanJson = preg_replace('/^```(?:json)?\s*/i', '', $cleanJson) ?? $cleanJson;
            $cleanJson = preg_replace('/\s*```$/', '', $cleanJson) ?? $cleanJson;
            $cleanJson = trim($cleanJson);
        }

        $raw = json_decode($cleanJson, true);
        if (!is_array($raw)) {
            return null;
        }

        $fieldConfMap = [];
        // Support Section 20 fields schema if present
        if (isset($raw['fields']) && is_array($raw['fields'])) {
            foreach ($raw['fields'] as $fk => $fv) {
                if (is_array($fv)) {
                    $raw[$fk] = $fv['value'] ?? null;
                    if (isset($fv['confidence'])) {
                        $fieldConfMap[$fk] = (float) $fv['confidence'];
                    }
                }
            }
        }

        // Support wrapped formats (extraction / extracted_data)
        if (isset($raw['extraction']) && is_array($raw['extraction'])) {
            $raw = array_merge($raw, $raw['extraction']);
        } elseif (isset($raw['extracted_data']) && is_array($raw['extracted_data'])) {
            $raw = array_merge($raw, $raw['extracted_data']);
        }

        // Map API response keys cleanly to application fields
        $lastName = $raw['surname'] ?? ($raw['last_name'] ?? null);
        $firstName = $raw['given_names'] ?? ($raw['first_name'] ?? null);
        $middleName = $raw['middle_name'] ?? null;
        $idNumber = $raw['document_number'] ?? ($raw['id_number'] ?? null);
        $dateOfBirth = $raw['date_of_birth'] ?? ($raw['birthdate'] ?? null);
        $address = $raw['address'] ?? null;
        $birthPlace = $raw['place_of_birth'] ?? ($raw['birth_place'] ?? null);
        $sex = $raw['sex'] ?? null;

        // Filter out isolated OCR noise fragments ("RE", "FI")
        if ($firstName !== null && in_array(mb_strtoupper(trim((string) $firstName)), ['RE', 'FI'], true)) {
            $firstName = null;
        }
        if ($lastName !== null && in_array(mb_strtoupper(trim((string) $lastName)), ['RE', 'FI'], true)) {
            $lastName = null;
        }

        // Strip OCR label prefixes if present in values
        $cleanValue = static function ($val): ?string {
            if ($val === null || trim((string) $val) === '') {
                return null;
            }
            $str = trim((string) $val);
            $str = preg_replace('/^(?:Given Names?|Mga Pangalan|First Name|Surname|Apelyido|Gitnang Apelyido|Middle Name|Address|Tirahan|Kasarian|Sex)\s*[:\-]\s*/iu', '', $str) ?? $str;
            return mb_strtoupper(trim($str), 'UTF-8');
        };

        $lastNameClean = $cleanValue($lastName);
        $firstNameClean = $cleanValue($firstName);

        if (empty($lastNameClean) && empty($firstNameClean)) {
            return null;
        }

        $docType = $raw['type'] ?? ($raw['id_type_detected'] ?? ($raw['metadata']['document_type'] ?? 'Philippine National ID'));
        $confidenceScore = isset($raw['metadata']['confidence_score'])
            ? (float) $raw['metadata']['confidence_score']
            : (isset($raw['confidence_score']) ? (float) $raw['confidence_score'] : 0.99);

        $parsedFields = [
            'first_name'    => $firstNameClean,
            'middle_name'   => $cleanValue($middleName),
            'last_name'     => $lastNameClean,
            'id_number'     => $idNumber !== null ? trim((string) $idNumber) : null,
            'date_of_birth' => $dateOfBirth !== null ? trim((string) $dateOfBirth) : null,
            'address'       => $cleanValue($address),
            'birth_place'   => $cleanValue($birthPlace),
            'sex'           => $sex !== null ? (str_contains(mb_strtoupper((string) $sex), 'FEMALE') || mb_strtoupper((string) $sex) === 'F' ? 'Female' : 'Male') : null,
        ];

        $fieldsAudit = [];
        $fieldConfidence = [];
        foreach ($parsedFields as $k => $val) {
            $c = $fieldConfMap[$k] ?? ($val !== null ? 0.99 : 0.0);
            $fieldConfidence[$k] = $c;
            $fieldsAudit[$k] = [
                'value'      => $val,
                'confidence' => $c,
                'status'     => ($val !== null && $c >= 0.85) ? 'verified' : ($val !== null ? 'needs_review' : 'not_found'),
                'source'     => 'gemini',
            ];
        }

        return [
            'id_type_detected' => $docType,
            'confidence_score' => $confidenceScore,
            'last_name'        => $lastNameClean,
            'first_name'       => $firstNameClean,
            'middle_name'      => $parsedFields['middle_name'],
            'id_number'        => $parsedFields['id_number'],
            'date_of_birth'    => $parsedFields['date_of_birth'],
            'address'          => $parsedFields['address'],
            'birth_place'      => $parsedFields['birth_place'],
            'sex'              => $parsedFields['sex'],
            'fields'           => $fieldsAudit,
            'field_confidence' => $fieldConfidence,
        ];
    }
}
