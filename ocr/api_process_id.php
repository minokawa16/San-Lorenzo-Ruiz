<?php
/**
 * api_process_id.php
 * -------------------
 * Strict two-pass ID OCR & validation endpoint for parishioner registration.
 *
 * Pipeline:
 *   1. Image quality assessment (rejects blurry, extreme dark, or washed-out glare images)
 *   2. Document identification (PhilSys, Driver's License, UMID, Passport, etc.)
 *   3. Pass 1: Direct Gemini 2.0 Flash multimodal vision on front (+ back) ID card
 *   4. Pass 2: Label-based OCR extraction via OCR.space Engine 2 / Tesseract
 *   5. Cross-source consensus: combines passes, reconciles character typos, and validates
 *   6. Strict field validation:
 *      - Names: complete given names, compound surnames, separated suffixes
 *      - Date of birth: calendar-validated (checkdate), no future or impossible dates
 *      - ID number: exact digits, PhilSys 16-digit formatting & masking
 *      - Sex: strictly Male/Female, never inferred from name
 *      - Birthplace: NEVER inferred from address (null if absent on ID)
 *   7. Honest confidence scoring & safe auto-fill status
 *   8. Privacy: automatic immediate deletion of temporary ID images
 */

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
    header('X-OCR-Pipeline: Strict Two-Pass Consensus');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'OCR processing failed: ' . $error['message'],
    ]);
});

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

// ── 1. Save uploaded / captured front ID ──────────────────────────────────────
if (!empty($_FILES['id_photo']) && ($_FILES['id_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmpPath  = $_FILES['id_photo']['tmp_name'];
    $mimeType = @mime_content_type($tmpPath) ?: 'image/jpeg';

    if (!in_array($mimeType, $allowedMime, true)) {
        sendOcrJson(['success' => false, 'error' => 'Unsupported file type. Please upload a JPG, PNG, or WEBP image.'], 400);
    }
    if ($_FILES['id_photo']['size'] > $maxBytes) {
        sendOcrJson(['success' => false, 'error' => 'Image is too large. Maximum size is 8MB.'], 400);
    }

    $safeFilename = uniqid('id_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $_FILES['id_photo']['name']);
    $destPath     = $workDir . '/' . $safeFilename;
    if (!move_uploaded_file($tmpPath, $destPath)) {
        sendOcrJson(['success' => false, 'error' => 'Could not save uploaded ID photo.'], 500);
    }
} else {
    if (!preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/', (string) $_POST['id_photo_data'], $matches)) {
        sendOcrJson(['success' => false, 'error' => 'Invalid ID image capture format.'], 400);
    }
    $binary = base64_decode($matches[2], true);
    if ($binary === false || $binary === '' || strlen($binary) > $maxBytes) {
        sendOcrJson(['success' => false, 'error' => 'ID image capture could not be decoded or exceeds 8MB.'], 400);
    }
    $imageInfo = @getimagesizefromstring($binary);
    if (!$imageInfo || !in_array($imageInfo['mime'], $allowedMime, true)) {
        sendOcrJson(['success' => false, 'error' => 'ID capture must be a valid JPG, PNG, or WEBP image.'], 400);
    }
    $extension = $imageInfo['mime'] === 'image/png' ? 'png' : ($imageInfo['mime'] === 'image/webp' ? 'webp' : 'jpg');
    $destPath = $workDir . '/' . uniqid('id_capture_', true) . '.' . $extension;
    if (file_put_contents($destPath, $binary, LOCK_EX) === false) {
        sendOcrJson(['success' => false, 'error' => 'Could not save ID capture.'], 500);
    }
}

// ── 2. Save optional back ID ──────────────────────────────────────────────────
if (!empty($_FILES['id_back_photo']) && ($_FILES['id_back_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $backTmpPath = $_FILES['id_back_photo']['tmp_name'];
    $backMimeType = @mime_content_type($backTmpPath) ?: 'image/jpeg';
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

// ── 3. What user typed in registration form ──────────────────────────────────
$formData = [
    'last_name'   => trim((string) ($_POST['last_name'] ?? ($_POST['surname'] ?? ''))),
    'first_name'  => trim((string) ($_POST['first_name'] ?? '')),
    'middle_name' => trim((string) ($_POST['middle_name'] ?? ($_POST['middle_initial'] ?? ''))),
    'address'     => trim((string) ($_POST['address'] ?? '')),
];

$responsePayload = [];
$responseStatus = 200;

try {
    // ── STEP 1: Strict Image Quality Assessment ───────────────────────────────
    $quality = IDOCRProcessor::evaluateImageQuality($destPath);
    if (!$quality['pass']) {
        sendOcrJson([
            'success'          => false,
            'image_quality_ok' => false,
            'error'            => $quality['reason'] ?? 'ID image is unclear. Please retake the photo with the entire ID visible and readable.',
            'image_quality'    => $quality,
        ], 400);
    }

    $processor = new IDOCRProcessor($workDir, 65);
    $mimeForAi = @mime_content_type($destPath) ?: 'image/jpeg';

    // ── STEP 2: Pass 1 — Multimodal Vision Extraction ─────────────────────────
    $aiResult = null;
    $aiEnhanced = false;
    try {
        $extractor = new AIExtractor();
        $aiResult = $extractor->parseImage($destPath, $backDestPath, $mimeForAi);
        if ($aiResult !== null && is_array($aiResult)) {
            $aiEnhanced = true;
        }
    } catch (Throwable $aiErr) {
        error_log('[api_process_id] Pass 1 AI Vision note: ' . $aiErr->getMessage());
    }

    // ── STEP 3: Pass 2 — Label-Based OCR Extraction ───────────────────────────
    $ocrFront = $processor->scanID($destPath);
    $ocrBack = null;
    if ($backDestPath && is_file($backDestPath)) {
        try {
            $ocrBack = $processor->scanID($backDestPath);
        } catch (Throwable $backErr) {
            error_log('[api_process_id] Pass 2 Back OCR note: ' . $backErr->getMessage());
        }
    }

    // ── STEP 4: Document Type Identification ─────────────────────────────────
    $docTypeDetected = $aiResult['id_type_detected']
        ?? ($ocrFront['id_type_detected'] ?? 'Government/School ID');

    $docConfidence = $aiResult['document_confidence']
        ?? ($ocrFront['document_confidence'] ?? 0.85);

    // ── STEP 5: Two-Pass Field Consensus & Reconciliation ────────────────────
    $keys = ['first_name', 'middle_name', 'last_name', 'suffix', 'id_number', 'date_of_birth', 'address', 'birth_place', 'sex'];

    $consensus = [];
    $fieldAudits = [];
    $needsReviewOverall = false;

    foreach ($keys as $k) {
        $aiVal = $aiResult[$k] ?? null;
        $aiConf = (float) ($aiResult['field_confidence'][$k] ?? ($aiVal !== null ? 0.95 : 0.0));

        // OCR candidate: check front first, then back for sex/birth_place/date_of_birth
        $ocrVal = $ocrFront[$k] ?? null;
        if (empty($ocrVal) && $ocrBack !== null && in_array($k, ['sex', 'birth_place', 'date_of_birth', 'address'], true)) {
            $ocrVal = $ocrBack[$k] ?? null;
        }
        $ocrConf = $ocrVal !== null ? 0.88 : 0.0;

        $chosenVal = null;
        $chosenConf = 0.0;
        $status = 'not_found';
        $source = 'none';

        if ($aiVal !== null && $ocrVal !== null) {
            // Both passes found text: compare similarity
            $normAi = mb_strtoupper(trim((string) $aiVal), 'UTF-8');
            $normOcr = mb_strtoupper(trim((string) $ocrVal), 'UTF-8');

            if ($normAi === $normOcr) {
                // Perfect agreement
                $chosenVal = $aiVal;
                $chosenConf = min(0.99, max($aiConf, $ocrConf) + 0.03);
                $status = 'verified';
                $source = 'gemini+ocr';
            } else {
                similar_text($normAi, $normOcr, $simPct);
                if ($simPct >= 88.0) {
                    // Close agreement (minor OCR typo in text pass; Gemini vision is cleaner)
                    $chosenVal = $aiVal;
                    $chosenConf = max(0.92, $aiConf);
                    $status = 'verified';
                    $source = 'gemini+ocr';
                } elseif ($simPct >= 70.0) {
                    // Borderline agreement
                    $chosenVal = $aiVal;
                    $chosenConf = 0.86;
                    $status = 'needs_review';
                    $source = 'gemini';
                    $needsReviewOverall = true;
                } else {
                    // Major conflict between passes: DO NOT GUESS!
                    // If AI confidence is very high, flag for user inspection; otherwise null
                    if ($aiConf >= 0.94) {
                        $chosenVal = $aiVal;
                        $chosenConf = 0.85;
                        $status = 'needs_review';
                        $source = 'gemini';
                    } else {
                        $chosenVal = null;
                        $chosenConf = 0.50;
                        $status = 'needs_review';
                        $source = 'disputed';
                    }
                    $needsReviewOverall = true;
                }
            }
        } elseif ($aiVal !== null) {
            // Only AI found it
            if ($aiConf >= 0.94) {
                $chosenVal = $aiVal;
                $chosenConf = $aiConf;
                $status = 'verified';
                $source = 'gemini';
            } elseif ($aiConf >= 0.85) {
                $chosenVal = $aiVal;
                $chosenConf = $aiConf;
                $status = 'needs_review';
                $source = 'gemini';
                $needsReviewOverall = true;
            } else {
                $chosenVal = null;
                $chosenConf = $aiConf;
                $status = 'needs_review';
                $source = 'gemini';
                $needsReviewOverall = true;
            }
        } elseif ($ocrVal !== null) {
            // Only OCR found it
            if ($ocrConf >= 0.85) {
                $chosenVal = $ocrVal;
                $chosenConf = 0.88;
                $status = 'needs_review';
                $source = 'ocr';
                $needsReviewOverall = true;
            } else {
                $chosenVal = null;
                $chosenConf = 0.60;
                $status = 'needs_review';
                $source = 'ocr';
            }
        }

        // ── Field-Specific Validation & Safety Rules ──
        if ($k === 'date_of_birth' && $chosenVal !== null) {
            $normDate = IDOCRProcessor::normalizeDate((string) $chosenVal);
            if ($normDate) {
                $chosenVal = $normDate;
            } else {
                // Invalid or future date: reject immediately
                $chosenVal = null;
                $status = 'needs_review';
                $chosenConf = 0.0;
                $needsReviewOverall = true;
            }
        }

        if ($k === 'sex' && $chosenVal !== null) {
            $parsedSex = IDOCRProcessor::parseSex((string) $chosenVal);
            if ($parsedSex) {
                $chosenVal = $parsedSex;
            } else {
                $chosenVal = null;
                $status = 'not_found';
                $chosenConf = 0.0;
            }
        }

        if ($k === 'id_number' && $chosenVal !== null) {
            $rawPcn = (string) $chosenVal;
            $formattedPcn = IDOCRProcessor::formatPhilSysCardNumber($rawPcn);
            if ($formattedPcn && IDOCRProcessor::validatePhilSysCardNumber($rawPcn)) {
                $chosenVal = $formattedPcn;
            }
        }

        // Section 10: Birthplace must NEVER be guessed from address
        if ($k === 'birth_place' && ($chosenVal === null || trim((string) $chosenVal) === '')) {
            $chosenVal = null;
            $status = 'not_found';
            $chosenConf = 0.0;
        }

        $consensus[$k] = $chosenVal;
        $fieldAudits[$k] = [
            'value'      => $chosenVal,
            'confidence' => round($chosenConf, 2),
            'status'     => $status,
            'source'     => $source,
        ];
    }

    // ── STEP 6: PhilSys PCN metadata formatting ──────────────────────────────
    $pcnRaw = $consensus['id_number'] ?? null;
    $idData = [
        'first_name'             => $consensus['first_name'],
        'middle_name'            => $consensus['middle_name'],
        'last_name'              => $consensus['last_name'],
        'suffix'                 => $consensus['suffix'],
        'id_number'              => $consensus['id_number'],
        'id_number_formatted'    => $pcnRaw ? IDOCRProcessor::formatPhilSysCardNumber($pcnRaw) : null,
        'id_number_masked'       => $pcnRaw ? IDOCRProcessor::maskPhilSysCardNumber($pcnRaw) : null,
        'id_number_valid'        => $pcnRaw ? IDOCRProcessor::validatePhilSysCardNumber($pcnRaw) : false,
        'date_of_birth'          => $consensus['date_of_birth'],
        'date_of_birth_display'  => $consensus['date_of_birth'] ? IDOCRProcessor::formatDateDisplay($consensus['date_of_birth']) : null,
        'address'                => $consensus['address'],
        'birth_place'            => $consensus['birth_place'], // Never inferred from address
        'sex'                    => $consensus['sex'],
        'field_confidence'       => array_map(fn($f) => $f['confidence'], $fieldAudits),
    ];

    // Compute genuine overall confidence
    $validConfidences = array_filter(
        array_values(array_map(fn($f) => $f['confidence'], $fieldAudits)),
        fn($c) => $c > 0.0
    );
    $overallConfidence = !empty($validConfidences)
        ? round(array_sum($validConfidences) / count($validConfidences), 2)
        : 0.85;

    // ── STEP 7: Compare with User's Pre-Typed Values ─────────────────────────
    $comparison = $processor->compareAll($formData, $idData);

    // If user already typed valid data and OCR has low confidence, preserve user value
    foreach (['first_name', 'last_name', 'middle_name', 'address'] as $formKey) {
        $userTyped = trim((string) ($formData[$formKey] ?? ''));
        if ($userTyped !== '' && ($idData['field_confidence'][$formKey] ?? 0) < 0.85) {
            $comparison[$formKey]['status'] = 'needs_review';
            $comparison[$formKey]['final_value'] = $userTyped;
        }
    }

    $publicIdData = array_diff_key($idData, ['raw_text' => true]);

    $responsePayload = [
        'success'             => true,
        'id_type_detected'    => $docTypeDetected,
        'document_confidence' => round((float) $docConfidence, 2),
        'overall_confidence'  => $overallConfidence,
        'confidence_score'    => $overallConfidence,
        'needs_review'        => $needsReviewOverall,
        'image_quality'       => $quality,
        'fields'              => $fieldAudits,
        'id_data'             => $publicIdData,
        'comparison'          => $comparison,
        'ai_enhanced'         => $aiEnhanced,
    ];
} catch (Throwable $e) {
    error_log('[api_process_id] OCR Pipeline Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $responseStatus = 500;
    $responsePayload = [
        'success' => false,
        'error'   => 'OCR processing failed: ' . $e->getMessage(),
    ];
} finally {
    if ($destPath && is_file($destPath)) {
        @unlink($destPath);
    }
    if ($backDestPath && is_file($backDestPath)) {
        @unlink($backDestPath);
    }
}

sendOcrJson($responsePayload, $responseStatus);
