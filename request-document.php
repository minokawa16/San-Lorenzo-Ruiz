<?php
/**
 * Request Document Module - Securely serves uploaded request documents to authorized users.
 */
require_once 'includes/session.php';
include 'database/config.php';
require_once 'includes/helpers.php';

requireLogin();

ensureRequestDocumentsSchema($conn);

$document_id = intval($_GET['id'] ?? 0);
if ($document_id <= 0) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = $conn->prepare("
    SELECT d.*, r.user_id
    FROM request_documents d
    JOIN requests r ON d.request_id = r.request_id
    WHERE d.document_id = ? AND d.deleted_at IS NULL
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    exit('Unable to load document.');
}

$stmt->bind_param('i', $document_id);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$can_manage_requests = hasPermission('requests.manage');
$owns_request = intval($document['user_id']) === intval($_SESSION['user_id']);
if (!$can_manage_requests && !$owns_request) {
    http_response_code(403);
    exit('Access denied.');
}

$raw_rel_path = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string)$document['file_path']), DIRECTORY_SEPARATOR);

$candidate_roots = array_filter([
    dirname(__DIR__),
    __DIR__,
    rtrim((string)(getenv('TUGON_DATA_DIR') ?: ''), '/\\'),
    rtrim((string)(getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: ''), '/\\'),
    sys_get_temp_dir(),
]);

$file_path = null;
if (is_file((string)$document['file_path'])) {
    $file_path = realpath((string)$document['file_path']);
}

if (!$file_path) {
    foreach ($candidate_roots as $root) {
        $test_path = $root . DIRECTORY_SEPARATOR . $raw_rel_path;
        if (is_file($test_path)) {
            $file_path = realpath($test_path);
            break;
        }
        $sub_test = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'request_requirements' . DIRECTORY_SEPARATOR . basename($raw_rel_path);
        if (is_file($sub_test)) {
            $file_path = realpath($sub_test);
            break;
        }
    }
}

if (!$file_path || !is_file($file_path)) {
    http_response_code(404);
    exit('Document file not found.');
}

$mime_type = $document['mime_type'] ?: 'application/octet-stream';
$filename = $document['original_name'] ?: basename($file_path);
$is_inline = (isRequestImageDocument($mime_type) || $mime_type === 'application/pdf');
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $is_inline = false;
}
$disposition = $is_inline ? 'inline' : 'attachment';

// Allow in-browser preview inside same-origin admin modals and iframes
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src 'self' data: blob: https:; font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

writeAuditLog($conn, (int) $_SESSION['user_id'], 'DOWNLOAD_REQUEST_DOCUMENT', 'request_documents', $document_id, null, null);
secureStreamFile($file_path, $mime_type, $filename, $disposition === 'inline');
