<?php
/**
 * Request Document Module - Securely serves uploaded request documents to authorized users.
 */
define('ALLOW_EMBEDDED_FRAMES', true);
require_once 'includes/session.php';
include 'database/config.php';
require_once 'includes/helpers.php';

requireLogin();

ensureRequestDocumentsSchema($conn);

$document_id = intval($_GET['id'] ?? 0);
if ($document_id <= 0) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Document not found.']);
    exit;
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => 'Unable to load document record.']);
    exit;
}

$stmt->bind_param('i', $document_id);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$document) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Document not found.']);
    exit;
}

$can_manage_requests = hasPermission('requests.manage')
    || isAdmin()
    || isBackOfficeUser()
    || (isset($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['admin', 'administrator', 'staff', 'coordinator'], true));

$owns_request = intval($document['user_id']) === intval($_SESSION['user_id'] ?? 0);
if (!$can_manage_requests && !$owns_request) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'ACCESS_DENIED', 'message' => 'Access denied. You do not have permission to view this document.']);
    exit;
}

$raw_path = (string)($document['file_path'] ?? '');
$clean_rel = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $raw_path), DIRECTORY_SEPARATOR);
$basename = basename($clean_rel);

$candidate_roots = array_filter(array_unique([
    __DIR__,
    dirname(__DIR__),
    rtrim((string)(getenv('TUGON_DATA_DIR') ?: ''), '/\\'),
    rtrim((string)(getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: ''), '/\\'),
    '/var/www/tugon-data',
    '/var/www/html',
    '/opt/tugon-seed',
    sys_get_temp_dir(),
]));

$file_path = null;
if ($raw_path !== '' && is_file($raw_path)) {
    $file_path = realpath($raw_path);
}

if (!$file_path) {
    foreach ($candidate_roots as $root) {
        if (!is_dir($root)) continue;

        $candidates = [
            $root . DIRECTORY_SEPARATOR . $clean_rel,
            $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'request_requirements' . DIRECTORY_SEPARATOR . $basename,
            $root . DIRECTORY_SEPARATOR . 'request_requirements' . DIRECTORY_SEPARATOR . $basename,
            $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $clean_rel,
            $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $basename,
        ];

        foreach ($candidates as $cand) {
            if (is_file($cand)) {
                $file_path = realpath($cand);
                break 2;
            }
        }
    }
}

if (!$file_path || !is_file($file_path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'FILE_NOT_FOUND',
        'message' => 'Document file not found on server storage.'
    ]);
    exit;
}

$filename = $document['original_name'] ?: basename($file_path);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

$detected_mime = null;
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected_mime = finfo_file($finfo, $file_path);
    finfo_close($finfo);
} elseif (function_exists('mime_content_type')) {
    $detected_mime = mime_content_type($file_path);
}

$ext_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'svg'  => 'image/svg+xml',
    'pdf'  => 'application/pdf',
];

if ($detected_mime && $detected_mime !== 'application/octet-stream') {
    $mime_type = $detected_mime;
} elseif (isset($ext_map[$ext])) {
    $mime_type = $ext_map[$ext];
} else {
    $mime_type = $document['mime_type'] ?: 'application/octet-stream';
}

$is_image = isRequestImageDocument($mime_type, $filename);
$is_pdf = ($mime_type === 'application/pdf' || $ext === 'pdf');
$is_inline = ($is_image || $is_pdf);

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $is_inline = false;
}
$disposition = $is_inline ? 'inline' : 'attachment';

writeAuditLog($conn, (int) $_SESSION['user_id'], 'DOWNLOAD_REQUEST_DOCUMENT', 'request_documents', $document_id, null, null);
secureStreamFile($file_path, $mime_type, $filename, $disposition === 'inline');
