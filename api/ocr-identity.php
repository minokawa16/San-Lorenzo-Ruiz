<?php
/**
 * Registration OCR Identity API - scans captured ID images and compares extracted fields.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

include '../database/config.php';
include '../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}

$images = [];
if (!empty($_POST['id_front_image'])) {
    $images[] = ['label' => 'front', 'data' => $_POST['id_front_image']];
}
if (!empty($_POST['id_back_image'])) {
    $images[] = ['label' => 'back', 'data' => $_POST['id_back_image']];
}
if (!$images && !empty($_POST['id_image'])) {
    $images[] = ['label' => 'id', 'data' => $_POST['id_image']];
}
if (!$images) {
    echo json_encode(['ok' => false, 'error' => 'Front or back ID image is required.']);
    exit;
}

$ocr_results = [];
foreach ($images as $image) {
    $capture = decodeCameraCapture($image['data'], 10 * 1024 * 1024);
    if (!$capture['ok']) {
        echo json_encode(['ok' => false, 'error' => ucfirst($image['label']) . ' ID: ' . $capture['error']]);
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'tugon_live_ocr_');
    if ($tmp === false || file_put_contents($tmp, $capture['binary']) === false) {
        echo json_encode(['ok' => false, 'error' => 'Unable to prepare ' . $image['label'] . ' ID image for OCR.']);
        exit;
    }

    $ocr_results[] = runValidIdOcr($tmp);
    @unlink($tmp);
}

$ocr = combineOcrResults($ocr_results);

$extracted = extractValidIdData($ocr['text']);
$submitted = [
    'fullname' => trim(($_POST['first_name'] ?? '') . ' ' . (($_POST['middle_initial'] ?? '') !== '' ? ($_POST['middle_initial'] ?? '') . '. ' : '') . ($_POST['surname'] ?? '')),
    'first_name' => $_POST['first_name'] ?? '',
    'surname' => $_POST['surname'] ?? '',
    'middle_initial' => $_POST['middle_initial'] ?? '',
    'birthdate' => $_POST['birthdate'] ?? '',
    'birth_place' => $_POST['birth_place'] ?? '',
    'address' => $_POST['address'] ?? '',
    'id_number' => $_POST['id_number'] ?? ''
];
$match = compareIdentityData($submitted, $extracted, $ocr['text']);
$confidence = getOcrFieldConfidence($extracted, $match['checks']);
$duplicate_id = false;
if (!empty($extracted['id_number'])) {
    $id_hash = hashIdentityNumber($extracted['id_number']);
    $id_hash_safe = $conn->real_escape_string($id_hash);
    $duplicate_result = $conn->query("SELECT id FROM users WHERE id_number_hash = '$id_hash_safe' LIMIT 1");
    $duplicate_id = $duplicate_result && $duplicate_result->num_rows > 0;
}

$missing_fields = array_filter(['birthdate', 'birth_place', 'address', 'id_number'], function ($field) use ($extracted) {
    return trim((string) ($extracted[$field] ?? '')) === '';
});
if (trim((string) ($extracted['full_name'] ?? '')) === '' && (trim((string) ($extracted['first_name'] ?? '')) === '' || trim((string) ($extracted['surname'] ?? '')) === '')) {
    $missing_fields[] = 'name';
}

if ($ocr['available'] && trim($ocr['text']) === '') {
    $match['status'] = 'unreadable';
} elseif (!$ocr['available']) {
    $match['status'] = 'ocr_unavailable';
} elseif (!empty($missing_fields)) {
    $match['status'] = 'needs_review';
}

echo json_encode([
    'ok' => true,
    'ocr_available' => $ocr['available'],
    'ocr_error' => $ocr['error'],
    'text' => $ocr['text'],
    'extracted' => $extracted,
    'checks' => $match['checks'],
    'confidence' => $confidence,
    'score' => intval($match['score']),
    'status' => $match['status'],
    'duplicate_id' => $duplicate_id
]);
