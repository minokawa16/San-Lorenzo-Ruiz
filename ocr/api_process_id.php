<?php
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
    header('X-OCR-Engine: Gemini 2.0 Flash Multimodal Vision');
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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once __DIR__ . '/IDOCRProcessor.php';
require_once __DIR__ . '/AIExtractor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendOcrJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

if ((empty($_FILES['id_photo']) || ($_FILES['id_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) && empty($_POST['id_photo_data'])) {
    sendOcrJson(['success' => false, 'error' => 'No ID photo uploaded, or upload failed.'], 400);
}

$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
$maxBytes    = 8 * 1024 * 1024; // 8 MB
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
    move_uploaded_file($tmpPath, $destPath);
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
    file_put_contents($destPath, $binary, LOCK_EX);
}

if (!empty($_FILES['id_back_photo']) && ($_FILES['id_back_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $backTmpPath = $_FILES['id_back_photo']['tmp_name'];
    $backMimeType = mime_content_type($backTmpPath);
    if (in_array($backMimeType, $allowedMime, true) && $_FILES['id_back_photo']['size'] <= $maxBytes) {
        $safeBackFilename = uniqid('id_back_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $_FILES['id_back_photo']['name']);
        $backDestPath = $workDir . '/' . $safeBackFilename;
        move_uploaded_file($backTmpPath, $backDestPath);
    }
} elseif (!empty($_POST['id_back_photo_data'])) {
    if (preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/', (string) $_POST['id_back_photo_data'], $backMatches)) {
        $backBinary = base64_decode($backMatches[2], true);
        if ($backBinary !== false && strlen($backBinary) <= $maxBytes) {
            $backImageInfo = @getimagesizefromstring($backBinary);
            if ($backImageInfo && in_array($backImageInfo['mime'], $allowedMime, true)) {
                $backExtension = $backImageInfo['mime'] === 'image/png' ? 'png' : ($backImageInfo['mime'] === 'image/webp' ? 'webp' : 'jpg');
                $backDestPath = $workDir . '/' . uniqid('id_back_capture_', true) . '.' . $backExtension;
                file_put_contents($backDestPath, $backBinary, LOCK_EX);
            }
        }
    }
}

$formData = [
    'last_name'   => trim($_POST['last_name'] ?? ($_POST['surname'] ?? '')),
    'first_name'  => trim($_POST['first_name'] ?? ''),
    'middle_name' => trim($_POST['middle_name'] ?? ($_POST['middle_initial'] ?? '')),
    'address'     => trim($_POST['address'] ?? ''),
];

$responsePayload = [];
$responseStatus = 200;

try {
    $processor = new IDOCRProcessor($workDir, 65);
    $idData = [
        'last_name' => null, 'first_name' => null, 'middle_name' => null,
        'date_of_birth' => null, 'birth_place' => null, 'id_number' => null,
        'address' => null, 'sex' => null,
        'field_confidence' => []
    ];

    $aiEnhanced = false;
    $idTypeDetected = null;
    $confidenceScore = 0.99;

    // Direct Multimodal Gemini Vision Processing (Front & Back concurrently)
    $mimeForAi = @mime_content_type($destPath) ?: 'image/jpeg';
    try {
        $extractor = new AIExtractor();
        $aiDirect = $extractor->parseImage($destPath, $backDestPath, $mimeForAi);
        
        if ($aiDirect !== null && is_array($aiDirect)) {
            $aiEnhanced = true;
            $idTypeDetected = $aiDirect['id_type_detected'] ?? 'Philippine National ID';
            $confidenceScore = (float) ($aiDirect['confidence_score'] ?? 0.99);

            foreach (['first_name', 'middle_name', 'last_name', 'id_number', 'date_of_birth', 'address', 'birth_place', 'sex'] as $k) {
                if (!empty($aiDirect[$k])) {
                    $idData[$k] = $aiDirect[$k];
                    $idData['field_confidence'][$k] = $confidenceScore;
                }
            }
        }
    } catch (Throwable $aiDirectError) {
        error_log('[api_process_id] AIExtractor error: ' . $aiDirectError->getMessage());
    }

    // Fallback to legacy OCR if AI did not return core names
    if (empty($idData['last_name']) || empty($idData['first_name'])) {
        $scannedFront = $processor->scanID($destPath);
        foreach ($scannedFront as $field => $val) {
            if ($field !== 'raw_text' && $field !== 'field_confidence' && !empty($val) && empty($idData[$field])) {
                $idData[$field] = $val;
            }
        }
    }

    // Normalizations
    if (!empty($idData['date_of_birth'])) {
        $normalizedDob = IDOCRProcessor::normalizeDate($idData['date_of_birth']);
        $idData['date_of_birth'] = $normalizedDob ?: $idData['date_of_birth'];
        $idData['date_of_birth_display'] = IDOCRProcessor::formatDateDisplay($idData['date_of_birth']);
    }

    if (!empty($idData['sex'])) {
        $idData['sex'] = IDOCRProcessor::parseSex($idData['sex']);
    }

    if (!empty($idData['id_number'])) {
        $rawPcn = (string) $idData['id_number'];
        $idData['id_number_formatted'] = IDOCRProcessor::formatPhilSysCardNumber($rawPcn);
        $idData['id_number_masked']    = IDOCRProcessor::maskPhilSysCardNumber($rawPcn);
        $idData['id_number_valid']     = IDOCRProcessor::validatePhilSysCardNumber($rawPcn);
        if ($idData['id_number_formatted']) {
            $idData['id_number'] = $idData['id_number_formatted'];
        }
    }

    if (empty($idData['birth_place'])) {
        $idData['birth_place'] = inferBirthPlaceFromAddress($idData['address'] ?? null);
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
    $responseStatus = 500;
    $responsePayload = ['success' => false, 'error' => 'OCR processing failed: ' . $e->getMessage()];
} finally {
    if ($destPath) @unlink($destPath);
    if ($backDestPath) @unlink($backDestPath);
}

sendOcrJson($responsePayload, $responseStatus);
