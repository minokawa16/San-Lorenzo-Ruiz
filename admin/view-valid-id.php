<?php
/**
 * Valid ID Review Module - Displays submitted identity documents during registration verification.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('registrations.verify');
ensureUserVerificationSchema($conn);

$user_id = intval($_GET['id'] ?? 0);
$requested_type = $_GET['type'] ?? 'id';
$type = in_array($requested_type, ['id', 'back', 'face'], true) ? $requested_type : 'id';
if ($user_id <= 0) {
    http_response_code(404);
    exit('Document not found.');
}

if ($type === 'face') {
    $stmt = $conn->prepare("SELECT face_image_path AS document_path, face_image_mime_type AS mime_type FROM users WHERE id = ? AND role = 'user' LIMIT 1");
} elseif ($type === 'back') {
    $stmt = $conn->prepare("SELECT valid_id_back_path AS document_path, valid_id_back_mime_type AS mime_type FROM users WHERE id = ? AND role = 'user' LIMIT 1");
} else {
    $stmt = $conn->prepare("SELECT valid_id_path AS document_path, valid_id_mime_type AS mime_type FROM users WHERE id = ? AND role = 'user' LIMIT 1");
}
if (!$stmt) {
    http_response_code(500);
    exit('Unable to load document.');
}

$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || empty($user['document_path'])) {
    http_response_code(404);
    exit('Document not found.');
}

$folder = $type === 'face' ? 'live_faces' : 'valid_ids';
$base_dir = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $folder);
$document_path = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $user['document_path']);
if (!$base_dir || !$document_path || strpos($document_path, $base_dir) !== 0) {
    http_response_code(403);
    exit('Invalid document path.');
}

$is_encrypted_document = substr($document_path, -4) === '.enc';
$contents = $is_encrypted_document ? decryptStoredFile($document_path) : file_get_contents($document_path);
if ($contents === false || $contents === '') {
    http_response_code(404);
    exit('Document could not be loaded.');
}

$mime_type = $user['mime_type'];
if (!in_array($mime_type, ['image/jpeg', 'image/png'], true)) {
    $extension = strtolower(pathinfo(str_replace('.enc', '', $document_path), PATHINFO_EXTENSION));
    $mime_type = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : ($extension === 'png' ? 'image/png' : 'application/octet-stream');
}

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . strlen($contents));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $contents;
