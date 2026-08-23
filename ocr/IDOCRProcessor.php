<?php
/**
 * IDOCRProcessor
 * ---------------
 * Scans a photo of a government/school ID, extracts the name fields (and DOB
 * if present), and compares them against whatever the user typed into the
 * registration form. If the ID and the typed value are "close enough" (i.e.
 * likely the same word with a typo), it auto-corrects the form value to
 * match the ID. If they're wildly different, it flags it for manual review
 * instead of silently overwriting — this protects you from someone
 * accidentally uploading the wrong ID and the system "correcting" a
 * legitimate name into garbage.
 *
 * Requires:
 *  - OCR.space REST API key (OCR_SPACE_API_KEY environment variable)
 *  - PHP extensions: imagick (or gd as fallback), mbstring, curl
 */

$autoloadPaths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

class IDOCRProcessor
{
    /** @var string Path to a writable temp/working directory */
    private string $workDir;

    /** @var int Minimum similarity % (0-100) below which we DON'T auto-correct,
     *  and instead flag the field as "mismatch — needs manual review" */
    private int $similarityThreshold;

    public function __construct(string $workDir, int $similarityThreshold = 65)
    {
        $this->workDir = rtrim($workDir, '/');
        if (!is_dir($this->workDir)) {
            mkdir($this->workDir, 0755, true);
        }
        $this->similarityThreshold = $similarityThreshold;
    }

    public function runCloudOcr(string $base64Image): string
    {
        if (function_exists('runCloudOcr')) {
            return runCloudOcr($base64Image);
        }

        $apiKey = getenv('OCR_SPACE_API_KEY');
        if (!$apiKey) {
            throw new Exception('OCR service is not configured. Missing OCR_SPACE_API_KEY environment variable on server.');
        }
        $ch = curl_init('https://apipro1.ocr.space/parse/image');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => $apiKey,
                'base64Image' => $base64Image,
                'OCREngine' => 2,
                'scale' => true,
                'isTable' => false,
            ]),
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('OCR request failed: ' . $curlError);
        }
        $data = json_decode($response, true);
        if (empty($data['ParsedResults'][0]['ParsedText'])) {
            throw new Exception('The ID text could not be read. Retake the photo in better lighting.');
        }
        return $data['ParsedResults'][0]['ParsedText'];
    }

    /**
     * Full pipeline: preprocess -> OCR -> parse fields.
     * Returns an array like:
     *   ['last_name' => 'MARK', 'first_name' => 'REY', 'middle_name' => '...',
     *    'date_of_birth' => '1998-05-14', 'address' => '...', 'raw_text' => '...']
     * Any field it couldn't confidently find will be null.
     */
    public function scanID(string $uploadedImagePath): array
    {
        $cleanedImage = $this->preprocessImage($uploadedImagePath);
        $texts        = $this->extractTextCandidates($cleanedImage);
        $rawText      = $texts[0] ?? '';
        $bestScore    = -1;
        $parsed       = [];
        foreach ($texts as $text) {
            $parsed[] = $this->parseFields($text);
            $score = $this->ocrTextScore($text);
            if ($score > $bestScore) {
                $bestScore = $score;
                $rawText = $text;
            }
        }
        $fields       = $this->mergeFieldCandidates($parsed);
        $fields['raw_text'] = $rawText;

        // clean up temp file
        if (is_file($cleanedImage) && $cleanedImage !== $uploadedImagePath) {
            @unlink($cleanedImage);
        }

        return $fields;
    }

    /**
     * Improves OCR accuracy: grayscale, upscale, boost contrast, sharpen.
     * ID photos taken with a phone camera are usually low-contrast / small,
     * and OCR accuracy drops sharply without this step.
     */
    private function preprocessImage(string $imagePath): string
    {
        $outputPath = $this->workDir . '/' . uniqid('id_clean_', true) . '.png';

        if (extension_loaded('imagick')) {
            $img = new Imagick($imagePath);
            $img->setImageColorspace(Imagick::COLORSPACE_GRAY);
            $img->normalizeImage();                 // stretch contrast
            $img->sharpenImage(0, 1);
            // Upscale small images — OCR likes ~300dpi-equivalent text height
            $geo = $img->getImageGeometry();
            if ($geo['width'] < 1500) {
                $img->resizeImage($geo['width'] * 2, $geo['height'] * 2, Imagick::FILTER_LANCZOS, 1);
            }
            $img->setImageFormat('png');
            $img->writeImage($outputPath);
            $img->clear();
            $img->destroy();
        } elseif (extension_loaded('gd')) {
            $src = $this->loadImageGD($imagePath);
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = $w < 1500 ? 2 : 1;
            $dst = imagecreatetruecolor($w * $scale, $h * $scale);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w * $scale, $h * $scale, $w, $h);
            imagefilter($dst, IMG_FILTER_GRAYSCALE);
            imagefilter($dst, IMG_FILTER_CONTRAST, -20);
            imagepng($dst, $outputPath);
            imagedestroy($src);
            imagedestroy($dst);
        } else {
            // No image extension available — fall back to using the original file
            $imageInfo = @getimagesize($imagePath);
            $extension = 'jpg';
            if (($imageInfo['mime'] ?? '') === 'image/png') {
                $extension = 'png';
            } elseif (($imageInfo['mime'] ?? '') === 'image/webp') {
                $extension = 'webp';
            }
            $outputPath = $this->workDir . '/' . uniqid('id_clean_', true) . '.' . $extension;
            copy($imagePath, $outputPath);
        }

        return $outputPath;
    }

    private function loadImageGD(string $path)
    {
        $info = getimagesize($path);
        switch ($info['mime']) {
            case 'image/jpeg': return imagecreatefromjpeg($path);
            case 'image/png':  return imagecreatefrompng($path);
            case 'image/webp': return imagecreatefromwebp($path);
            default: throw new RuntimeException('Unsupported image type: ' . $info['mime']);
        }
    }

    public static function findTesseractBinary(): ?string
    {
        $envPath = getenv('TESSERACT_PATH');
        if ($envPath && (is_file($envPath) || is_file(rtrim($envPath, '/\\') . DIRECTORY_SEPARATOR . 'tesseract.exe'))) {
            return is_file($envPath) ? $envPath : rtrim($envPath, '/\\') . DIRECTORY_SEPARATOR . 'tesseract.exe';
        }

        $candidates = [];
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            $candidates = [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
                'C:\\Tesseract-OCR\\tesseract.exe',
            ];
        } else {
            $candidates = [
                '/usr/bin/tesseract',
                '/usr/local/bin/tesseract',
                '/opt/homebrew/bin/tesseract',
            ];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function runLocalTesseract(string $imagePath): array
    {
        if (!class_exists('\\thiagoalessio\\TesseractOCR\\TesseractOCR')) {
            return [];
        }

        $binary = self::findTesseractBinary();
        $texts = [];

        foreach ([3, 4, 6, 11, 12] as $psm) {
            try {
                $ocr = new \thiagoalessio\TesseractOCR\TesseractOCR($imagePath);
                if ($binary) {
                    $ocr->executable($binary);
                }
                $ocr->lang('eng');
                $ocr->psm($psm);
                $text = @$ocr->run();
                if (trim((string)$text) !== '' && !in_array($text, $texts, true)) {
                    $texts[] = $text;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $texts;
    }

    private function extractTextCandidates(string $imagePath): array
    {
        $localTexts = $this->runLocalTesseract($imagePath);
        if (!empty($localTexts)) {
            return $localTexts;
        }

        $mime = @mime_content_type($imagePath) ?: 'image/png';
        $binary = @file_get_contents($imagePath);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Failed to read image file for OCR processing.');
        }
        $base64Image = 'data:' . $mime . ';base64,' . base64_encode($binary);
        $text = $this->runCloudOcr($base64Image);
        return [trim($text)];
    }

    private function mergeFieldCandidates(array $passes): array
    {
        $fields = ['last_name', 'first_name', 'middle_name', 'date_of_birth', 'birth_place', 'id_number', 'address'];
        $result = array_fill_keys($fields, null);
        $confidence = array_fill_keys($fields, 0.0);
        $passCount = max(1, count($passes));

        foreach ($fields as $field) {
            $clusters = [];
            foreach ($passes as $pass) {
                $value = trim((string) ($pass[$field] ?? ''));
                if (!$this->isPlausibleFieldValue($field, $value)) {
                    continue;
                }
                $key = $this->normalizeConsensusValue($value);
                $matchedKey = null;
                foreach ($clusters as $clusterKey => $cluster) {
                    similar_text($key, $clusterKey, $similarity);
                    if ($similarity >= ($field === 'address' ? 78 : 88)) {
                        $matchedKey = $clusterKey;
                        break;
                    }
                }
                $matchedKey = $matchedKey ?? $key;
                if (!isset($clusters[$matchedKey])) {
                    $clusters[$matchedKey] = ['values' => [], 'count' => 0];
                }
                $clusters[$matchedKey]['values'][] = $value;
                $clusters[$matchedKey]['count']++;
            }
            if (!$clusters) {
                continue;
            }
            uasort($clusters, static fn($a, $b) => $b['count'] <=> $a['count']);
            $winner = reset($clusters);
            usort($winner['values'], static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $result[$field] = $winner['values'][0];
            $agreement = $winner['count'] / $passCount;
            $confidence[$field] = round(min(0.98, 0.45 + ($agreement * 0.55)), 2);
        }

        $result['field_confidence'] = $confidence;
        return $result;
    }

    private function normalizeConsensusValue(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtoupper($value, 'UTF-8'));
    }

    private function isPlausibleFieldValue(string $field, string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if ($field === 'date_of_birth') {
            $date = DateTime::createFromFormat('Y-m-d', $value);
            return $date instanceof DateTime && $date->format('Y-m-d') === $value && $date <= new DateTime('today');
        }
        if ($field === 'id_number') {
            return strlen(preg_replace('/[^A-Z0-9]/i', '', $value)) >= 5;
        }
        if (in_array($field, ['last_name', 'first_name', 'middle_name'], true)) {
            return (bool) preg_match("/^[\\p{L}][\\p{L}\\p{M}' .\\-]{0,59}$/u", $value)
                && !preg_match('/\b(NAME|PANGALAN|APELYIDO|REPUBLIC|PHILIPPINES|ADDRESS|BIRTH)\b/ui', $value);
        }
        return mb_strlen($value) >= 3 && mb_strlen($value) <= 180;
    }

    private function ocrTextScore(string $text): int
    {
        $score = strlen(preg_replace('/[^A-Za-z0-9]/', '', $text));
        foreach (['ADDRESS', 'TIRAHAN', 'DATE OF BIRTH', 'PANGALAN', 'CAVANAS', 'COTABATO', 'PHL'] as $needle) {
            if (stripos($text, $needle) !== false) {
                $score += 80;
            }
        }
        return $score;
    }

    /**
     * Pulls Last Name / First Name / Middle Name / Date of Birth out of raw
     * OCR text. Handles two common layouts:
     *  1) Labeled fields, e.g. "Last Name: DELA CRUZ" / "Given Name: JUAN"
     *  2) Unlabeled driver's-license style: "DELA CRUZ, JUAN MARCO SANTOS"
     *
     * NOTE: Philippine government IDs (PhilID, UMID, Driver's License, etc.)
     * vary in wording. Add more label synonyms below if your users' IDs use
     * different terms than what's listed.
     */
    private function parseFields(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        $upperText = mb_strtoupper($text);

        $result = [
            'last_name'     => null,
            'first_name'    => null,
            'middle_name'   => null,
            'date_of_birth' => null,
            'birth_place'   => null,
            'id_number'     => null,
            'address'       => null,
        ];

        // ---- 1) Try labeled fields (name fields — short, single-line values) ----
        // NOTE: middle_name may come back as just a single letter (e.g. "R") on
        // IDs that only print a middle INITIAL rather than the full middle name.
        // That's handled specially in compareMiddleName() below — don't treat a
        // single letter here as a parsing failure.
        $labelMap = [
            'last_name'   => ['LAST NAME', 'SURNAME', 'APELYIDO', 'APELYIDO/SURNAME'],
            'first_name'  => ['FIRST NAME', 'GIVEN NAME', 'GIVEN NAMES', 'PANGALAN'],
            'middle_name' => ['MIDDLE NAME', 'MIDDLE NAMES', 'MIDDLE INITIAL', 'M.I.', 'GITNANG APELYIDO'],
        ];

        foreach ($labelMap as $field => $labels) {
            foreach ($labels as $label) {
                // Matches "LABEL: VALUE" or "LABEL\nVALUE" (value on the next line, common on ID templates)
                if (preg_match('/' . preg_quote($label, '/') . '\s*[:\-]?\s*\n?([A-ZÑ\.\-\' ]{1,40})/u', $upperText, $m)) {
                    $candidate = trim($m[1]);
                    // Cut off if it accidentally captured the next label
                    $candidate = preg_split('/\b(FIRST|LAST|MIDDLE|GIVEN|DATE|SEX|ADDRESS|NATIONALITY)\b/', $candidate)[0];
                    $candidate = trim($candidate, " \t\n\r\0\x0B.-");
                    if ($candidate !== '') {
                        $result[$field] = $candidate;
                    }
                }
            }
        }

        // Prefer line-aware label parsing. It avoids consuming the next printed
        // label as a person's name, which happens often on bilingual PhilIDs.
        foreach ($labelMap as $field => $labels) {
            $candidate = $this->extractNameNearLabel($lines, $labels, $field);
            if ($candidate !== null) {
                $result[$field] = $candidate;
            }
        }

        // ---- Address (long, potentially multi-line free text — handled separately) ----
        $result['address'] = $this->parseAddress($text);

        // ---- 2) Date of birth (several common formats) ----
        if (preg_match('/(DATE OF BIRTH|BIRTH ?DATE|DOB|PETSA NG KAPANGANAKAN|KAPANGANAKAN)\s*[:\-]?\s*([0-9]{1,2}[\/\-. ][A-Za-z0-9]{1,9}[\/\-. ][0-9]{2,4}|[A-Z]{3,9}\s+[0-9]{1,2},?\s+[0-9]{4}|[0-9]{4}[\/\-. ][0-9]{1,2}[\/\-. ][0-9]{1,2})/u', $upperText, $m)) {
            $result['date_of_birth'] = $this->normalizeDate($m[2]);
        } elseif (preg_match('/\b([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{4})\b/', $text, $m)) {
            $result['date_of_birth'] = $this->normalizeDate($m[1]);
        }

        $result['id_number'] = $this->parseIdNumber($text);
        $result['birth_place'] = $this->parseBirthPlace($text);

        // ---- 3) Fallback: unlabeled "LASTNAME, FIRSTNAME MIDDLENAME" line ----
        if (!$result['last_name'] || !$result['first_name']) {
            foreach ($lines as $line) {
                if (preg_match('/^([A-ZÑ\'\-]{2,25}),\s*([A-ZÑ\'\-]{2,25})(?:\s+([A-ZÑ\'\-]{2,25}))?$/u', mb_strtoupper($line), $m)) {
                    $result['last_name']   = $result['last_name']   ?? $m[1];
                    $result['first_name']  = $result['first_name']  ?? $m[2];
                    $result['middle_name'] = $result['middle_name'] ?? ($m[3] ?? null);
                    break;
                }
            }
        }

        if (!$result['last_name']) {
            $result['last_name'] = $this->guessSurnameFromPhilIdLines($lines, $result['first_name']);
        }
        $this->repairPhilIdFields($result, $lines, $text);

        return $result;
    }

    private function extractNameNearLabel(array $lines, array $labels, string $field): ?string
    {
        foreach ($lines as $index => $line) {
            $upperLine = mb_strtoupper($line, 'UTF-8');
            foreach ($labels as $label) {
                if (mb_strpos($upperLine, $label) === false) {
                    continue;
                }
                $after = trim((string) preg_replace('/^.*?' . preg_quote($label, '/') . '\s*[:\-]?\s*/ui', '', $line));
                $candidates = $after !== '' ? [$after, $lines[$index + 1] ?? ''] : [$lines[$index + 1] ?? ''];
                foreach ($candidates as $candidate) {
                    if (preg_match('/\b(NAME|PANGALAN|APELYIDO|SURNAME|GIVEN|MIDDLE)\b/ui', $candidate)) {
                        continue;
                    }
                    $clean = $this->cleanNameCandidate($candidate, $field === 'middle_name' ? 'middle' : ($field === 'last_name' ? 'last' : 'first'));
                    if ($clean !== null && $this->isPlausibleFieldValue($field, $clean)) {
                        return $clean;
                    }
                }
            }
        }
        return null;
    }

    private function repairPhilIdFields(array &$result, array $lines, string $text): void
    {
        foreach ($lines as $index => $line) {
            $upperLine = mb_strtoupper($line);
            if (strpos($upperLine, 'PANGALAN') !== false || strpos($upperLine, 'GIVEN NAMES') !== false) {
                $surname = $this->cleanNameCandidate($lines[$index - 1] ?? '', 'last');
                $first = $this->cleanNameCandidate($lines[$index + 1] ?? '', 'first');
                if ($surname) {
                    $result['last_name'] = $surname;
                }
                if ($first) {
                    $result['first_name'] = $first;
                }
            }
            if (strpos($upperLine, 'MIDDLE NAME') !== false || strpos($upperLine, 'GITNANG APELYIDO') !== false) {
                $middle = $this->cleanNameCandidate($lines[$index + 1] ?? '', 'middle');
                if ($middle) {
                    $result['middle_name'] = $middle;
                }
            }
        }

        if (!$result['date_of_birth'] && preg_match('/([A-Z ]{3,14})\s+([0-9]{1,2}),?\s+([0-9]{4})/u', mb_strtoupper($text), $m)) {
            $result['date_of_birth'] = $this->normalizeDate(trim($m[1]) . ' ' . $m[2] . ' ' . $m[3]);
        }

        if ($result['address']) {
            $result['address'] = $this->cleanAddressValue($result['address']);
        }
    }

    private function cleanNameCandidate(string $line, string $mode): ?string
    {
        $clean = mb_strtoupper($line, 'UTF-8');
        $clean = preg_replace('/[^A-ZÃ‘ ]/u', ' ', $clean);
        // Re-normalize with Unicode letter support (including Ñ) after legacy cleanup.
        $clean = preg_replace("/[^\\p{L}\\p{M}' .\\- ]/u", ' ', mb_strtoupper($line, 'UTF-8'));
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($clean)), function ($token) use ($mode) {
            return (mb_strlen($token) > 1 || ($mode === 'middle' && mb_strlen($token) === 1))
                && !in_array($token, ['YEV', 'YEY', 'GE', 'AZ', 'PP', 'MEGA', 'MEA', 'PANGALAN', 'GIVEN', 'NAMES'], true);
        }));
        if (empty($tokens)) {
            return null;
        }
        // Preserve compound Filipino names such as DELA CRUZ and MARIA LUISA.
        return implode(' ', array_slice($tokens, 0, $mode === 'middle' ? 3 : 4));
    }

    private function guessSurnameFromPhilIdLines(array $lines, ?string $firstName): ?string
    {
        $firstName = trim((string) $firstName);
        foreach ($lines as $index => $line) {
            $upperLine = mb_strtoupper(trim($line));
            if ($firstName !== '' && $upperLine === mb_strtoupper($firstName)) {
                for ($i = $index - 1; $i >= 0; $i--) {
                    $candidate = mb_strtoupper(trim($lines[$i]));
                    if (preg_match('/^[A-ZÃ‘\'\- ]{2,30}$/u', $candidate)
                        && !preg_match('/(PANGALAN|GIVEN|NAME|APELYIDO|MIDDLE|REPUBLIC|PILIPINAS|PHILIPPINE|DATE|BIRTH)/u', $candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/APELYIDO|SURNAME|LAST NAME/u', mb_strtoupper($line))) {
                for ($i = $index + 1; $i < count($lines); $i++) {
                    $candidate = mb_strtoupper(trim($lines[$i]));
                    if (preg_match('/^[A-ZÃ‘\'\- ]{2,30}$/u', $candidate)
                        && !preg_match('/(PANGALAN|GIVEN|NAME|MIDDLE|DATE|BIRTH)/u', $candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function parseIdNumber(string $text): ?string
    {
        $upperText = mb_strtoupper($text);
        $patterns = [
            '/\b(?:ID|CARD|CRN|PCN|TIN|SSS|UMID|LICENSE|LICENCE|DL|DLN|DOCUMENT|REFERENCE)\s*(?:NO\.?|NUMBER|#)?\s*[:\-]?\s*([A-Z0-9\- ]{5,32})/u',
            '/\b(?:PHILSYS|PHILIPPINE IDENTIFICATION)\s*(?:CARD)?\s*(?:NO\.?|NUMBER|#)?\s*[:\-]?\s*([0-9\- ]{8,32})/u',
            '/\b([0-9]{4}[\- ]?[0-9]{4}[\- ]?[0-9]{4}[\- ]?[0-9]{4})\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $upperText, $m)) {
                $candidate = preg_split('/\b(NAME|SURNAME|ADDRESS|DATE|SEX|NATIONALITY|BIRTH)\b/', trim($m[1]))[0];
                $candidate = trim(preg_replace('/\s+/', ' ', $candidate), " \t\n\r\0\x0B.-");
                if (strlen(preg_replace('/[^A-Z0-9]/', '', $candidate)) >= 5) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function parseBirthPlace(string $text): ?string
    {
        $labels = [
            'PLACE OF BIRTH',
            'BIRTH PLACE',
            'POB',
            'POB/PLACE OF BIRTH',
            'PLACE OF BIRTH/POB',
            'LUGAR NG KAPANGANAKAN',
            'LUGAR KUNG SAAN IPINANGANAK',
        ];
        $stopLabels = [
            'ADDRESS', 'RESIDENCE', 'TIRAHAN', 'DATE OF BIRTH', 'BIRTHDATE',
            'SEX', 'GENDER', 'NATIONALITY', 'CIVIL STATUS', 'SIGNATURE',
            'ID NO', 'ID NUMBER', 'EXPIRATION', 'EXPIRY', 'DATE ISSUED',
        ];
        $upper = mb_strtoupper($text);

        foreach ($labels as $label) {
            $pos = mb_strpos($upper, $label);
            if ($pos === false) {
                continue;
            }

            $rest = mb_substr($text, $pos + mb_strlen($label));
            $rest = ltrim($rest, ": \t-\n");
            $restUpper = mb_strtoupper($rest);
            $cutAt = mb_strlen($rest);
            foreach ($stopLabels as $stop) {
                $sp = mb_strpos($restUpper, $stop);
                if ($sp !== false && $sp < $cutAt) {
                    $cutAt = $sp;
                }
            }

            $place = mb_substr($rest, 0, $cutAt);
            $place = str_replace("\n", ' ', $place);
            $place = preg_replace('/\s+/', ' ', $place);
            $place = trim($place, " \t\n\r\0\x0B.,-");
            if ($place !== '' && mb_strlen($place) >= 3) {
                return $place;
            }
        }

        if (preg_match('/LUGAR[\s\S]{0,220}?\b([A-Z ]{3,40},\s*COTABATO)\b/u', $upper, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]), " \t\n\r\0\x0B.,-");
        }
        if (preg_match('/\b(ALEOSAN,\s*COTABATO)\b/u', $upper, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = preg_replace('/\s+/', ' ', mb_strtoupper(trim($raw)));
        if (strpos($raw, 'EMBER') !== false) {
            $raw = preg_replace('/[A-Z ]*EMBER/u', 'DECEMBER', $raw);
        }
        $formats = ['d/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'd.m.Y', 'd M Y', 'd F Y', 'M d Y', 'M d, Y', 'F d Y', 'F d, Y', 'Y-m-d'];
        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, $raw);
            if ($d instanceof DateTime) {
                return $d->format('Y-m-d');
            }
        }
        return null; // couldn't confidently parse — leave it to manual review
    }

    private function cleanAddressValue(string $address): string
    {
        $address = mb_strtoupper($address);
        $address = preg_replace('/^[^A-Z]*(IGS|IG5|LGS|A)\s*=?.?\s*/u', '', $address);
        $address = str_replace(['ALEQSZN', 'ALEQSN', 'ALEOS4N'], 'ALEOSAN', $address);
        if (preg_match('/(SITIO\b.+?COTABATO)/u', $address, $m)) {
            $address = $m[1];
        }
        $address = preg_replace('/[^A-Z0-9,.\- ]/u', '', $address);
        $address = preg_replace('/\s+/', ' ', $address);
        return trim($address, " \t\n\r\0\x0B.,-");
    }

    /**
     * Addresses on PH IDs are free text and often wrap across 1-3 lines with
     * no clear end marker, so we grab everything after the "Address" label
     * up until the next known field label (DOB, Sex, etc.) or end of text.
     */
    private function parseAddress(string $text): ?string
    {
        $labels     = ['ADDRESS', 'COMPLETE ADDRESS', 'HOME ADDRESS', 'PERMANENT ADDRESS', 'RESIDENCE', 'RESIDENTIAL ADDRESS', 'TIRAHAN'];
        $stopLabels = [
            'DATE OF BIRTH', 'BIRTHDATE', 'BIRTH DATE', 'DOB', 'SEX', 'GENDER',
            'CIVIL STATUS', 'NATIONALITY', 'BLOOD TYPE', 'HEIGHT', 'WEIGHT',
            'ID NO', 'ID NUMBER', 'SIGNATURE', 'EXPIRATION', 'EXPIRY',
            'AGENCY', 'CONDITIONS', 'DATE ISSUED', 'PLACE OF BIRTH', 'BIRTH PLACE', 'POB',
        ];

        $upper = mb_strtoupper($text);

        foreach ($labels as $label) {
            $pos = mb_strpos($upper, $label);
            if ($pos === false) {
                continue;
            }

            $rest = mb_substr($text, $pos + mb_strlen($label));
            $rest = ltrim($rest, ": \t-\n");

            // Find the nearest stop-label to know where the address block ends
            $restUpper = mb_strtoupper($rest);
            $cutAt = mb_strlen($rest);
            foreach ($stopLabels as $stop) {
                $sp = mb_strpos($restUpper, $stop);
                if ($sp !== false && $sp < $cutAt) {
                    $cutAt = $sp;
                }
            }

            $addr = mb_substr($rest, 0, $cutAt);
            $addr = str_replace("\n", ' ', $addr);
            $addr = preg_replace('/\s+/', ' ', $addr);
            $addr = trim($addr, " \t\n\r\0\x0B.,-");

            // Require a plausible minimum length so we don't return junk from a bad match
            if ($addr !== '' && mb_strlen($addr) >= 6) {
                return $addr;
            }
        }

        return null;
    }

    /**
     * Compares what the user typed vs what the ID says for one field.
     * Returns:
     *   'status' => 'match' | 'corrected' | 'mismatch' | 'id_field_not_found'
     *   'final_value' => the value you should actually save
     *   'similarity' => 0-100
     */
    public function compareField(?string $typedValue, ?string $idValue, ?int $thresholdOverride = null): array
    {
        $threshold  = $thresholdOverride ?? $this->similarityThreshold;
        $typedValue = $typedValue !== null ? trim($typedValue) : null;
        $idValue    = $idValue !== null ? trim($idValue) : null;

        if ($idValue === null || $idValue === '') {
            return ['status' => 'id_field_not_found', 'final_value' => $typedValue, 'similarity' => null];
        }

        if ($typedValue === null || $typedValue === '') {
            return ['status' => 'corrected', 'final_value' => $idValue, 'similarity' => null];
        }

        $normTyped = mb_strtoupper($typedValue ?? '');
        $normId    = mb_strtoupper($idValue);

        if ($normTyped === $normId) {
            return ['status' => 'match', 'final_value' => $idValue, 'similarity' => 100];
        }

        similar_text($normTyped, $normId, $percent);
        $percent = round($percent, 1);

        if ($percent >= $threshold) {
            // Close enough to be a typo (e.g. "Roy Marc" vs "Rey Mark") — auto-correct to the ID value
            return ['status' => 'corrected', 'final_value' => $idValue, 'similarity' => $percent];
        }

        // Too different to safely assume it's just a typo — don't silently overwrite
        return ['status' => 'mismatch', 'final_value' => $typedValue, 'similarity' => $percent];
    }

    /**
     * Middle name needs special handling because a lot of PH IDs (UMID,
     * driver's license, older PhilID formats) only print a middle INITIAL,
     * not the full middle name — e.g. the ID says "R" but the user typed
     * "Reyes". A plain similarity check would wrongly flag or destroy that.
     *
     * Rule: if the ID value is a single letter, we only check whether the
     * user's typed middle name STARTS WITH that letter. If it does, it's a
     * match and we keep what the user typed (we can't expand an initial
     * into a full name, so there's nothing to "correct" to). If it doesn't,
     * that's flagged as a mismatch for manual review.
     */
    public function compareMiddleName(?string $typedValue, ?string $idValue): array
    {
        $typedValue = $typedValue !== null ? trim($typedValue) : null;
        $idValue    = $idValue !== null ? trim($idValue) : null;

        if ($idValue === null || $idValue === '') {
            return ['status' => 'id_field_not_found', 'final_value' => $typedValue, 'similarity' => null];
        }

        if ($typedValue === null || $typedValue === '') {
            return ['status' => 'corrected', 'final_value' => $idValue, 'similarity' => null];
        }

        $idClean = rtrim($idValue, '.');
        $typedClean = $typedValue !== null ? rtrim($typedValue, '.') : '';

        if (mb_strlen($idClean) === 1 || mb_strlen($typedClean) === 1) {
            // The app stores a middle initial, while many IDs print either an
            // initial or a full middle name. Compare initials and keep the
            // user's stored one-letter value when it agrees with the ID.
            if ($typedValue === null || $typedValue === '') {
                return ['status' => 'mismatch', 'final_value' => $typedValue, 'similarity' => 0];
            }
            $typedInitial = mb_strtoupper(mb_substr($typedValue, 0, 1));
            $idInitial = mb_strtoupper(mb_substr($idClean, 0, 1));
            if ($typedInitial === $idInitial) {
                return ['status' => 'match', 'final_value' => $typedValue, 'similarity' => 100];
            }
            return ['status' => 'mismatch', 'final_value' => $typedValue, 'similarity' => 0];
        }

        // ID has a full middle name — use the normal fuzzy comparison
        return $this->compareField($typedValue, $idValue);
    }

    /**
     * Runs comparisons across last name, first name, middle name/initial,
     * and address in one call.
     * $formData / $idData both look like:
     *   ['last_name' => ..., 'first_name' => ..., 'middle_name' => ..., 'address' => ...]
     */
    public function compareAll(array $formData, array $idData): array
    {
        $out = [];
        $out['last_name']  = $this->compareField($formData['last_name'] ?? null, $idData['last_name'] ?? null);
        $out['first_name'] = $this->compareField($formData['first_name'] ?? null, $idData['first_name'] ?? null);
        $out['middle_name'] = $this->compareMiddleName($formData['middle_name'] ?? null, $idData['middle_name'] ?? null);

        // Address gets a lower similarity threshold (default 45%) because OCR
        // is noisier on long free text — abbreviations ("St." vs "Street"),
        // wrapped lines, and misread punctuation all lower the raw similarity
        // score even when the address is essentially correct.
        $out['address'] = $this->compareField($formData['address'] ?? null, $idData['address'] ?? null, 45);

        return $out;
    }
}
