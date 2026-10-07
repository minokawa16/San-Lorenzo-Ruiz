<?php
declare(strict_types=1);

/**
 * StructOcrClient
 * ---------------
 * Thin, defensive client for the StructOCR "National ID" API.
 *
 *   POST https://api.structocr.com/v1/national-id
 *   Headers: Content-Type: application/json, x-api-key: <STRUCTOCR_API_KEY>
 *   Body:    {"img": "data:image/jpeg;base64,...."}      (decoded image <= 4.5 MB)
 *
 * Response (200): {"success": true, "data": {surname, given_names, sex, date_of_birth,
 *                  place_of_birth, address, document_number, ...}}
 *
 * IMPORTANT DESIGN NOTES
 *  - This client only READS what StructOCR returned. It never guesses or fills gaps.
 *  - Dates are re-validated with IDOCRProcessor::normalizeDate() (calendar-checked).
 *  - Pairs front and back ID images:
 *      * Front provides: surname, given names, PCN document number, date of birth, address
 *      * Back provides: place of birth, sex, secondary barcode / series
 *
 * Configuration: STRUCTOCR_API_KEY (environment variable or .env file).
 */
class StructOcrClient
{
    public const ENDPOINT   = 'https://api.structocr.com/v1/national-id';
    private const MAX_BYTES = 4400000; // API limit is 4.5 MB of decoded image; keep headroom.

    private int $timeout;
    private string $lastError = '';
    private int $lastHttp = 0;

    public function __construct(int $timeoutSeconds = 30)
    {
        $this->timeout = $timeoutSeconds;
    }

    public static function getApiKey(): string
    {
        if (function_exists('tugonLoadEnvFile')) {
            tugonLoadEnvFile();
        }

        if (defined('STRUCTOCR_API_KEY') && STRUCTOCR_API_KEY !== '') {
            return (string) STRUCTOCR_API_KEY;
        }

        $key = trim((string) (getenv('STRUCTOCR_API_KEY') ?: ($_ENV['STRUCTOCR_API_KEY'] ?? '')));
        if ($key !== '') {
            return $key;
        }

        // Check if the user set their StructOCR key under OCR_SPACE_API_KEY
        $alt = trim((string) (getenv('OCR_SPACE_API_KEY') ?: ($_ENV['OCR_SPACE_API_KEY'] ?? '')));
        if (str_starts_with($alt, 'sk_')) {
            return $alt;
        }

        // Fallback: direct inspection of project .env
        $envPath = dirname(__DIR__) . '/.env';
        if (is_file($envPath) && is_readable($envPath)) {
            $content = (string) file_get_contents($envPath);
            if (preg_match('/^STRUCTOCR_API_KEY=(.+)$/m', $content, $m)) {
                $candidate = trim($m[1]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            if (preg_match('/^OCR_SPACE_API_KEY=(sk_[A-Za-z0-9_]+)$/m', $content, $m2)) {
                $candidate = trim($m2[1]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    public function isConfigured(): bool
    {
        return self::getApiKey() !== '';
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastHttp;
    }

    /**
     * Extracts fields from both front and back ID images and merges them accurately.
     *
     * @return array<string,mixed>|null
     */
    public function extractPair(string $frontImagePath, ?string $backImagePath = null): ?array
    {
        $frontData = $this->extract($frontImagePath);
        if (!$frontData && empty($backImagePath)) {
            return null;
        }

        $backData = null;
        if (!empty($backImagePath) && is_file($backImagePath)) {
            $backData = $this->extract($backImagePath);
        }

        if (!$frontData && !$backData) {
            return null;
        }

        $frontDoc = $frontData['id_number'] ?? null;
        $backDoc  = $backData['id_number'] ?? null;

        // Front document number on PhilSys is 16-digit PCN (e.g. 6573-2841-6302-1426)
        $primaryDocNumber = $frontDoc ?: $backDoc;

        $merged = [
            'id_type_detected' => $frontData['id_type_detected'] ?? $backData['id_type_detected'] ?? 'Philippine National ID / PhilSys',
            'last_name'        => $frontData['last_name'] ?? $backData['last_name'] ?? null,
            'first_name'       => $frontData['first_name'] ?? $backData['first_name'] ?? null,
            'middle_name'      => $frontData['middle_name'] ?? $backData['middle_name'] ?? null,
            'id_number'        => $primaryDocNumber,
            'date_of_birth'    => $frontData['date_of_birth'] ?? $backData['date_of_birth'] ?? null,
            'address'          => $frontData['address'] ?? $backData['address'] ?? null,
            // On PhilSys National ID, birth place and sex are printed on the card back
            'birth_place'      => $backData['birth_place'] ?? $frontData['birth_place'] ?? null,
            'sex'              => $backData['sex'] ?? $frontData['sex'] ?? null,
        ];

        return $merged;
    }

    /**
     * Sends one ID image to StructOCR and returns the normalized application fields,
     * or null when the call failed / nothing usable was returned (see getLastError()).
     *
     * @return array<string,mixed>|null
     */
    public function extract(string $imagePath): ?array
    {
        $this->lastError = '';
        $this->lastHttp  = 0;

        $apiKey = self::getApiKey();
        if ($apiKey === '') {
            $this->lastError = 'STRUCTOCR_API_KEY is not configured.';
            return null;
        }

        $dataUri = $this->buildDataUri($imagePath);
        if ($dataUri === null) {
            return null;
        }

        $payload = json_encode(['img' => $dataUri], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $this->lastError = 'Could not encode request payload.';
            return null;
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-key: ' . $apiKey,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $this->lastHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $response === '') {
            $this->lastError = 'StructOCR request failed: ' . ($curlErr ?: 'empty response');
            return null;
        }

        $json = json_decode((string) $response, true);
        if (!is_array($json)) {
            $this->lastError = 'StructOCR returned a non-JSON response (HTTP ' . $this->lastHttp . ').';
            return null;
        }

        if ($this->lastHttp !== 200 || empty($json['success']) || !isset($json['data']) || !is_array($json['data'])) {
            $code = (string) ($json['error'] ?? $json['code'] ?? 'UNKNOWN');
            $msg  = (string) ($json['message'] ?? '');
            $this->lastError = 'StructOCR error ' . $this->lastHttp . ' ' . $code . ($msg !== '' ? ': ' . $msg : '');
            return null;
        }

        return $this->normalize($json['data']);
    }

    /**
     * Maps StructOCR's schema to the application's field names WITHOUT inventing values.
     * Every value that is missing/empty stays null.
     *
     * @param  array<string,mixed> $d
     * @return array<string,mixed>
     */
    public function normalize(array $d): array
    {
        $clean = static function ($v): ?string {
            if ($v === null) {
                return null;
            }
            $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
            return $s === '' || strtolower($s) === 'null' ? null : mb_strtoupper($s, 'UTF-8');
        };

        $add = isset($d['additional_fields']) && is_array($d['additional_fields']) ? $d['additional_fields'] : [];

        // Sex: only accept explicit M/F/MALE/FEMALE. Never inferred from the name.
        $sex = null;
        $rawSex = $clean($d['sex'] ?? null);
        if ($rawSex !== null) {
            if ($rawSex === 'M' || $rawSex === 'MALE' || $rawSex === 'LALAKI') {
                $sex = 'Male';
            } elseif ($rawSex === 'F' || $rawSex === 'FEMALE' || $rawSex === 'BABAE') {
                $sex = 'Female';
            }
        }

        // Date of birth: must be a real calendar date, otherwise null (do not guess).
        $dob = null;
        if (!empty($d['date_of_birth'])) {
            $dob = IDOCRProcessor::normalizeDate((string) $d['date_of_birth']);
        }

        // ID number: preserved exactly as printed (only trimmed).
        $idNumber = isset($d['document_number']) && trim((string) $d['document_number']) !== ''
            ? trim((string) $d['document_number'])
            : null;

        $middle = $clean($d['middle_name'] ?? ($add['middle_name'] ?? null));

        return [
            'id_type_detected' => (($d['type'] ?? '') === 'national_id' && ($d['country_code'] ?? '') === 'PHL')
                ? 'Philippine National ID / PhilSys'
                : (isset($d['type']) ? (string) $d['type'] : null),
            'last_name'     => $clean($d['surname'] ?? null),
            'first_name'    => $clean($d['given_names'] ?? null),
            'middle_name'   => $middle,
            'id_number'     => $idNumber,
            'date_of_birth' => $dob,
            'address'       => $clean($d['address'] ?? null),
            'birth_place'   => $clean($d['place_of_birth'] ?? null),
            'sex'           => $sex,
        ];
    }

    /**
     * Builds a "data:image/...;base64,..." string, downscaling with GD only if the
     * image would exceed StructOCR's 4.5 MB limit.
     */
    private function buildDataUri(string $imagePath): ?string
    {
        if (!is_file($imagePath)) {
            $this->lastError = 'Image file not found.';
            return null;
        }
        $bytes = (string) file_get_contents($imagePath);
        if ($bytes === '') {
            $this->lastError = 'Image file is empty.';
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? 'image/jpeg';

        if (strlen($bytes) > self::MAX_BYTES && extension_loaded('gd')) {
            $src = @imagecreatefromstring($bytes);
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                $scale = min(1.0, 2000 / max($w, $h));
                $dst = imagecreatetruecolor((int) max(1, $w * $scale), (int) max(1, $h * $scale));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
                ob_start();
                imagejpeg($dst, null, 88);
                $bytes = (string) ob_get_clean();
                $mime  = 'image/jpeg';
                imagedestroy($src);
                imagedestroy($dst);
            }
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            $this->lastError = 'Image is larger than 4.5 MB.';
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
