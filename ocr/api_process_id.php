<?php
/**
 * api_process_id.php
 * -------------------
 * Endpoint called (via fetch/AJAX) from the registration page AFTER the
 * user has typed their name into the form AND selected/uploaded their ID
 * photo, but BEFORE the form is finally submitted.
 *
 * Flow:
 *   1. Receives the ID image + the values the user typed.
 *   2. Runs OCR on the ID.
 *   3. Compares typed vs ID values field by field.
 *   4. Returns JSON telling the front-end which fields were auto-corrected,
 *      which matched, and which need the user's manual attention.
 *
 * The front-end then updates the visible form fields with the corrected
 * values before the user hits final "Register".
 */

declare(strict_types=1);
ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json');

function sendOcrJson(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json');
    header('X-OCR-Engine: OCR.space Cloud API');
    header('X-OCR-Build: 0b0db4e-cloud');
    echo json_encode($payload);
    exit;
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'OCR processing failed: ' . $error['message'],
    ]);
});

function inferBirthPlaceFromAddress(?string $address): ?string
{
    $address = trim((string) $address);
    if ($address === '') {
        return null;
    }

    $parts = array_values(array_filter(array_map('trim', explode(',', $address)), static fn($part) => $part !== ''));
    if (count($parts) >= 2) {
        return mb_strtoupper($parts[count($parts) - 2] . ', ' . $parts[count($parts) - 1]);
    }

    return null;
}

if (!function_exists('runCloudOcr')) {
    function runCloudOcr(string $base64Image): string
    {
        $apiKey = getenv('OCR_SPACE_API_KEY') ?: (defined('OCR_SPACE_API_KEY') ? OCR_SPACE_API_KEY : 'K81271400388957');
        if (!$apiKey) {
            throw new Exception('OCR service is not configured. Missing OCR_SPACE_API_KEY.');
        }
        $ch = curl_init('https://apipro1.ocr.space/parse/image');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => $apiKey,
                'base64Image' => $base64Image,
                'OCREngine' => 2,
                'scale' => 'true',
                'isTable' => 'false',
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
            $err = $data['ErrorMessage'][0] ?? 'The ID text could not be read. Retake the photo in better lighting.';
            throw new Exception($err);
        }
        return $data['ParsedResults'][0]['ParsedText'];
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once __DIR__ . '/IDOCRProcessor.php';
require_once __DIR__ . '/AIExtractor.php';

// ---- basic guards ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendOcrJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

if ((empty($_FILES['id_photo']) || ($_FILES['id_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) && empty($_POST['id_photo_data'])) {
    sendOcrJson(['success' => false, 'error' => 'No ID photo uploaded, or upload failed.'], 400);
}

// ---- validate the uploaded image (type + size) ----
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
$maxBytes    = 8 * 1024 * 1024; // 8 MB

// ---- move to a private working dir (NOT web-accessible) ----
$workDir = dirname(__DIR__) . '/storage/tmp_ids';
if (!is_dir($workDir)) {
    mkdir($workDir, 0755, true);
}
$destPath = null;
$backDestPath = null;

if (!empty($_FILES['id_photo']) && ($_FILES['id_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmpPath  = $_FILES['id_photo']['tmp_name'];
    $mimeType = mime_content_type($tmpPath);

    if (!in_array($mimeType, $allowedMime, true)) {
        sendOcrJson(['success' => false, 'error' => 'Unsupported file type. Please upload a JPG, PNG, or WEBP image.'], 400);
    }

    if ($_FILES['id_photo']['size'] > $maxBytes) {
        sendOcrJson(['success' => false, 'error' => 'Image is too large. Max size is 8MB.'], 400);
    }

    $safeFilename = uniqid('id_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $_FILES['id_photo']['name']);
    $destPath     = $workDir . '/' . $safeFilename;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        sendOcrJson(['success' => false, 'error' => 'Could not save uploaded file.'], 500);
    }
} else {
    if (!preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/', (string) $_POST['id_photo_data'], $matches)) {
        sendOcrJson(['success' => false, 'error' => 'Invalid ID image capture.'], 400);
    }

    $binary = base64_decode($matches[2], true);
    if ($binary === false || $binary === '' || strlen($binary) > $maxBytes) {
        sendOcrJson(['success' => false, 'error' => 'ID image capture could not be decoded or is too large.'], 400);
    }

    $imageInfo = @getimagesizefromstring($binary);
    if (!$imageInfo || !in_array($imageInfo['mime'], $allowedMime, true)) {
        sendOcrJson(['success' => false, 'error' => 'ID capture must be a valid JPG, PNG, or WEBP image.'], 400);
    }

    $extension = $imageInfo['mime'] === 'image/png' ? 'png' : ($imageInfo['mime'] === 'image/webp' ? 'webp' : 'jpg');
    $destPath = $workDir . '/' . uniqid('id_capture_', true) . '.' . $extension;
    if (file_put_contents($destPath, $binary, LOCK_EX) === false) {
        sendOcrJson(['success' => false, 'error' => 'Could not save uploaded ID capture.'], 500);
    }
}

if (!empty($_POST['id_back_photo_data'])) {
    if (preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/', (string) $_POST['id_back_photo_data'], $backMatches)) {
        $backBinary = base64_decode($backMatches[2], true);
        if ($backBinary !== false && $backBinary !== '' && strlen($backBinary) <= $maxBytes) {
            $backImageInfo = @getimagesizefromstring($backBinary);
            if ($backImageInfo && in_array($backImageInfo['mime'], $allowedMime, true)) {
                $backExtension = $backImageInfo['mime'] === 'image/png' ? 'png' : ($backImageInfo['mime'] === 'image/webp' ? 'webp' : 'jpg');
                $backDestPath = $workDir . '/' . uniqid('id_back_capture_', true) . '.' . $backExtension;
                if (file_put_contents($backDestPath, $backBinary, LOCK_EX) === false) {
                    $backDestPath = null;
                }
            }
        }
    }
}

// ---- what the user typed in the registration form ----
$formData = [
    'last_name'   => trim($_POST['last_name'] ?? ($_POST['surname'] ?? '')),
    'first_name'  => trim($_POST['first_name'] ?? ''),
    'middle_name' => trim($_POST['middle_name'] ?? ($_POST['middle_initial'] ?? '')),
    'address'     => trim($_POST['address'] ?? ''),
];

$responsePayload = [];
$responseStatus = 200;

try {
    $processor = new IDOCRProcessor($workDir, 65); // 65% similarity threshold, tweak as needed
    $idData    = [
        'last_name'     => null,
        'first_name'    => null,
        'middle_name'   => null,
        'date_of_birth' => null,
        'birth_place'   => null,
        'id_number'     => null,
        'address'       => null,
        'sex'           => null,
        'field_confidence' => [
            'last_name'     => 0.0,
            'first_name'    => 0.0,
            'middle_name'   => 0.0,
            'date_of_birth' => 0.0,
            'birth_place'   => 0.0,
            'id_number'     => 0.0,
            'address'       => 0.0,
            'sex'           => 0.0,
        ],
    ];

    $aiEnhanced     = false;
    $idTypeDetected = null;
    $confidenceScore = null;

    // ── STEP 1: Try direct Gemini multimodal document vision on the front ID ──
    $mimeForAi = @mime_content_type($destPath) ?: 'image/jpeg';
    try {
        $extractor = new AIExtractor();
        $aiDirect = $extractor->parseImage($destPath, $mimeForAi);
        if ($aiDirect !== null && is_array($aiDirect)) {
            $aiEnhanced     = true;
            $idTypeDetected = $aiDirect['id_type_detected'] ?? 'Philippine National ID';
            $confidenceScore = (float) ($aiDirect['confidence_score'] ?? 0.95);

            foreach (['first_name', 'middle_name', 'last_name', 'id_number', 'date_of_birth', 'address', 'birth_place', 'sex'] as $k) {
                if (!empty($aiDirect[$k])) {
                    $idData[$k] = $aiDirect[$k];
                    $idData['field_confidence'][$k] = max(0.90, $confidenceScore);
                }
            }
        }
    } catch (Throwable $aiDirectError) {
        error_log('[api_process_id] AIExtractor::parseImage direct multimodal error: ' . $aiDirectError->getMessage());
    }

    // ── STEP 2: If any core field is missing or AI is unavailable, run OCR processor ──
    $needsOcr = empty($idData['last_name']) || empty($idData['first_name']) || empty($idData['id_number']);
    if ($needsOcr) {
        $scannedFront = $processor->scanID($destPath);
        $scannedConfidence = $scannedFront['field_confidence'] ?? [];
        foreach ($scannedFront as $field => $val) {
            if ($field === 'raw_text') {
                $idData['raw_text'] = $val;
                continue;
            }
            if ($field === 'field_confidence') {
                continue;
            }
            if (!empty($val) && empty($idData[$field])) {
                $idData[$field] = $val;
                $idData['field_confidence'][$field] = (float) ($scannedConfidence[$field] ?? 0.70);
            }
        }
    }

    // ── STEP 3: If back ID was provided, scan and merge back side details ──
    if ($backDestPath) {
        try {
            $backData = $processor->scanID($backDestPath);
            $backConfidence = $backData['field_confidence'] ?? [];
            if (!empty($backData['raw_text'])) {
                $idData['raw_text'] = trim(($idData['raw_text'] ?? '') . "\n" . (string) $backData['raw_text']);
            }
            // Philippine National ID back side contains Sex, Place of Birth, and Blood Type.
            // NEVER allow back ID to overwrite or populate cardholder name fields or PCN!
            foreach (['sex', 'birth_place', 'address'] as $field) {
                $value = $backData[$field] ?? null;
                if ($value !== null && $value !== '') {
                    $curScore = (float) ($idData['field_confidence'][$field] ?? 0);
                    $backScore = (float) ($backConfidence[$field] ?? 0.85);
                    if (empty($idData[$field]) || $backScore >= $curScore) {
                        $idData[$field] = $value;
                        $idData['field_confidence'][$field] = $backScore;
                    }
                }
            }
        } catch (Throwable $backErr) {
            error_log('[api_process_id] Back ID scan notice: ' . $backErr->getMessage());
        }
    }

    // ── STEP 4: If AI was not used yet and raw OCR text is present, run text-based AI cleaning ──
    if (!$aiEnhanced && !empty($idData['raw_text'])) {
        try {
            $extractor = new AIExtractor();
            $aiResult  = $extractor->parse((string) $idData['raw_text']);
            if ($aiResult !== null) {
                $aiEnhanced     = true;
                $idTypeDetected = $aiResult['id_type_detected'] ?? null;
                $confidenceScore = (float) ($aiResult['confidence_score'] ?? 0.90);
                foreach (['first_name', 'middle_name', 'last_name', 'id_number', 'date_of_birth', 'address', 'birth_place', 'sex'] as $k) {
                    if (!empty($aiResult[$k])) {
                        $idData[$k] = $aiResult[$k];
                        $idData['field_confidence'][$k] = max(0.92, $confidenceScore);
                    }
                }
            }
        } catch (Throwable $aiError) {
            error_log('[api_process_id] AIExtractor::parse text error: ' . $aiError->getMessage());
        }
    }

    // ── STEP 5: PhilSys Normalization & Validation ──
    // Date of Birth normalization (ISO YYYY-MM-DD + Display format)
    if (!empty($idData['date_of_birth'])) {
        $normalizedDob = IDOCRProcessor::normalizeDate($idData['date_of_birth']);
        if ($normalizedDob) {
            $idData['date_of_birth'] = $normalizedDob;
            $idData['date_of_birth_display'] = IDOCRProcessor::formatDateDisplay($normalizedDob);
        } else {
            $idData['date_of_birth_display'] = $idData['date_of_birth'];
        }
    } else {
        $idData['date_of_birth_display'] = null;
    }

    // Sex normalization
    if (!empty($idData['sex'])) {
        $idData['sex'] = IDOCRProcessor::parseSex($idData['sex']);
    }

    // PhilSys Card Number / PCN formatting and masking
    if (!empty($idData['id_number'])) {
        $rawPcn = (string) $idData['id_number'];
        $idData['id_number_formatted'] = IDOCRProcessor::formatPhilSysCardNumber($rawPcn);
        $idData['id_number_masked']    = IDOCRProcessor::maskPhilSysCardNumber($rawPcn);
        $idData['id_number_valid']     = IDOCRProcessor::validatePhilSysCardNumber($rawPcn);
        // Use formatted 16-digit PCN as main id_number value
        if ($idData['id_number_formatted']) {
            $idData['id_number'] = $idData['id_number_formatted'];
        }
    } else {
        $idData['id_number_formatted'] = null;
        $idData['id_number_masked']    = null;
        $idData['id_number_valid']     = false;
    }

    // Birth place inference if still empty
    if (empty($idData['birth_place'])) {
        $idData['birth_place'] = inferBirthPlaceFromAddress($idData['address'] ?? null);
        if ($idData['birth_place']) {
            $idData['field_confidence']['birth_place'] = 0.50;
        }
    }

    $comparison = $processor->compareAll($formData, $idData);

    $publicIdData = array_diff_key($idData, ['raw_text' => true]);
    $responsePayload = [
        'success'          => true,
        'id_data'          => $publicIdData,
        'comparison'       => $comparison,
        'ai_enhanced'      => $aiEnhanced,
        'id_type_detected' => $idTypeDetected,
        'confidence_score' => $confidenceScore,
    ];
} catch (Throwable $e) {
    error_log('[api_process_id] OCR processing failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $responseStatus = 500;
    $responsePayload = ['success' => false, 'error' => 'OCR processing failed: ' . $e->getMessage()];
} finally {
    // Delete the ID photo after processing — don't retain sensitive ID images
    // longer than necessary. Remove this line only if you have a compliant,
    // encrypted, consented reason to keep ID scans on file.
    if ($destPath) {
        @unlink($destPath);
    }
    if ($backDestPath) {
        @unlink($backDestPath);
    }
}

sendOcrJson($responsePayload, $responseStatus);
