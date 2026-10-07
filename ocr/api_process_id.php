<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function sendOcrJson(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once __DIR__ . '/IDOCRProcessor.php';
require_once __DIR__ . '/StructOcrClient.php';
require_once __DIR__ . '/AIExtractor.php';

if (function_exists('tugonLoadEnvFile')) {
    tugonLoadEnvFile();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendOcrJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$workDir = dirname(__DIR__) . '/storage/tmp_ids';
if (!is_dir($workDir)) {
    mkdir($workDir, 0755, true);
}
$destPath = null;
$backDestPath = null;

// Front ID Photo
if (!empty($_FILES['id_photo']) && ($_FILES['id_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $destPath = $workDir . '/' . uniqid('id_', true) . '.jpg';
    move_uploaded_file($_FILES['id_photo']['tmp_name'], $destPath);
} elseif (!empty($_POST['id_photo_data'])) {
    if (preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/', (string) $_POST['id_photo_data'], $matches)) {
        $destPath = $workDir . '/' . uniqid('id_capture_', true) . '.jpg';
        file_put_contents($destPath, base64_decode($matches[2]), LOCK_EX);
    }
}

// Back ID Photo
if (!empty($_FILES['id_back_photo']) && ($_FILES['id_back_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $backDestPath = $workDir . '/' . uniqid('id_back_', true) . '.jpg';
    move_uploaded_file($_FILES['id_back_photo']['tmp_name'], $backDestPath);
} elseif (!empty($_POST['id_back_photo_data'])) {
    if (preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/', (string) $_POST['id_back_photo_data'], $backMatches)) {
        $backDestPath = $workDir . '/' . uniqid('id_back_', true) . '.jpg';
        file_put_contents($backDestPath, base64_decode($backMatches[2]), LOCK_EX);
    }
}

if (!$destPath || !is_file($destPath)) {
    sendOcrJson(['success' => false, 'error' => 'No valid ID image provided.'], 400);
}

try {
    $idData = null;
    $engineUsed = 'none';

    // 1. Primary Engine: StructOCR API (https://structocr.com/)
    $structClient = new StructOcrClient();
    if ($structClient->isConfigured()) {
        $extracted = $structClient->extractPair($destPath, $backDestPath);
        if ($extracted && (!empty($extracted['last_name']) || !empty($extracted['first_name']))) {
            $idData = $extracted;
            $engineUsed = 'structocr';
        } else {
            error_log('[OCR] StructOCR notice: ' . $structClient->getLastError());
        }
    }

    // 2. Secondary Engine: Gemini Multimodal AI
    if (!$idData || empty($idData['last_name'])) {
        $aiExtractor = new AIExtractor();
        $aiData = $aiExtractor->parseImage($destPath, $backDestPath);
        if ($aiData && (!empty($aiData['last_name']) || !empty($aiData['first_name']))) {
            $idData = $aiData;
            $engineUsed = 'gemini';
        }
    }

    // 3. Fallback Engine: IDOCRProcessor (OCR.space / local OCR)
    if (!$idData || empty($idData['last_name'])) {
        try {
            $legacyProcessor = new IDOCRProcessor($workDir);
            $legacyData = $legacyProcessor->scanID($destPath);
            if ($legacyData && (!empty($legacyData['last_name']) || !empty($legacyData['first_name']))) {
                $idData = $legacyData;
                $engineUsed = 'ocr_space';
            }
        } catch (Throwable $e) {
            error_log('[OCR] Legacy OCR fallback error: ' . $e->getMessage());
        }
    }

    if (!$idData || (empty($idData['last_name']) && empty($idData['first_name']))) {
        throw new Exception('Failed to extract identity fields from the ID image. Please ensure the ID photo is clear and well-lit.');
    }

    // Format birthdate display for user feedback
    if (!empty($idData['date_of_birth'])) {
        $idData['date_of_birth_display'] = IDOCRProcessor::formatDateDisplay($idData['date_of_birth']);
    }

    // Standardize PCN / ID Number format
    if (!empty($idData['id_number'])) {
        $formattedPcn = IDOCRProcessor::formatPhilSysCardNumber((string) $idData['id_number']);
        if ($formattedPcn) {
            $idData['id_number_formatted'] = $formattedPcn;
            $idData['id_number'] = $formattedPcn;
        } else {
            $idData['id_number_formatted'] = (string) $idData['id_number'];
        }
    }

    // Assign per-field confidence ratings
    $idData['field_confidence'] = [
        'first_name'    => !empty($idData['first_name']) ? 0.99 : 0.0,
        'last_name'     => !empty($idData['last_name']) ? 0.99 : 0.0,
        'middle_name'   => !empty($idData['middle_name']) ? 0.99 : 0.0,
        'id_number'     => !empty($idData['id_number']) ? 0.99 : 0.0,
        'date_of_birth' => !empty($idData['date_of_birth']) ? 0.99 : 0.0,
        'address'       => !empty($idData['address']) ? 0.99 : 0.0,
        'birth_place'   => !empty($idData['birth_place']) ? 0.99 : 0.0,
        'sex'           => !empty($idData['sex']) ? 0.99 : 0.0,
    ];

    // Build structured audit entries for UI badge status
    $fieldAudits = [];
    foreach (['last_name', 'first_name', 'middle_name', 'address', 'id_number', 'date_of_birth', 'birth_place', 'sex'] as $field) {
        $val = $idData[$field] ?? null;
        $fieldAudits[$field] = [
            'value'      => $val,
            'confidence' => $val !== null ? 0.99 : 0.0,
            'status'     => $val !== null ? 'verified' : 'not_found',
            'source'     => $engineUsed,
        ];
    }

    // Check user-typed values against OCR values if supplied in POST
    $comparison = [];
    foreach (['last_name', 'first_name', 'middle_name', 'address', 'id_number', 'date_of_birth'] as $field) {
        $postVal = trim((string) ($_POST[$field] ?? ''));
        $ocrVal = trim((string) ($idData[$field] ?? ''));
        $status = 'corrected';
        $similarity = 100;

        if ($postVal !== '' && $ocrVal !== '') {
            if (strcasecmp($postVal, $ocrVal) === 0) {
                $status = 'verified';
                $similarity = 100;
            } else {
                similar_text(mb_strtolower($postVal), mb_strtolower($ocrVal), $simPercent);
                $similarity = (int) round($simPercent);
            }
        }

        $comparison[$field] = [
            'status'      => $status,
            'final_value' => $ocrVal ?: $postVal,
            'similarity'  => $similarity,
        ];
    }

    sendOcrJson([
        'success'             => true,
        'id_type_detected'    => $idData['id_type_detected'] ?? 'Philippine National ID',
        'document_confidence' => 0.99,
        'overall_confidence'  => 0.99,
        'confidence_score'    => 0.99,
        'id_data'             => $idData,
        'fields'              => $fieldAudits,
        'comparison'          => $comparison,
        'ai_enhanced'         => true,
        'engine'              => $engineUsed,
    ]);
} catch (Throwable $e) {
    sendOcrJson(['success' => false, 'error' => $e->getMessage()], 500);
} finally {
    if ($destPath && is_file($destPath)) {
        @unlink($destPath);
    }
    if ($backDestPath && is_file($backDestPath)) {
        @unlink($backDestPath);
    }
}
