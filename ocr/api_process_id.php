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
    echo json_encode($payload);
    exit;
}

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

// ---- basic guards ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendOcrJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$csrfName = csrfTokenName();
if (!verifyCsrfToken($_POST[$csrfName] ?? '')) {
    sendOcrJson(['success' => false, 'error' => 'Your secure session token expired. Please refresh the registration page and try again.'], 403);
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
    $idData    = $processor->scanID($destPath);
    if ($backDestPath) {
        $backData = $processor->scanID($backDestPath);
        foreach ($backData as $field => $value) {
            if ($field === 'raw_text') {
                $idData['raw_text'] = trim(($idData['raw_text'] ?? '') . "\n" . ($value ?? ''));
                continue;
            }
            if (($idData[$field] ?? null) === null && $value !== null && $value !== '') {
                $idData[$field] = $value;
            }
        }
        if (!empty($backData['birth_place'])) {
            $idData['birth_place'] = $backData['birth_place'];
        }
    }
    if (empty($idData['birth_place'])) {
        $idData['birth_place'] = inferBirthPlaceFromAddress($idData['address'] ?? null);
    }
    $comparison = $processor->compareAll($formData, $idData);

    $responsePayload = [
        'success'       => true,
        'id_data'       => array_diff_key($idData, ['raw_text' => true]),
        'comparison'    => $comparison,    // per-field status + final_value to use
    ];
} catch (Throwable $e) {
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
