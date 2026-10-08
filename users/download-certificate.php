<?php
/**
 * Parishioner Certificate Download & View Module
 *
 * Securely serves the admin-uploaded certificate file for completed requests.
 * Only the request owner or authorized admin/staff can download or view.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();

$user_id = intval($_SESSION['user_id'] ?? 0);
$request_id = intval($_GET['request_id'] ?? ($_GET['id'] ?? 0));
$is_inline_view = isset($_GET['view']) || isset($_GET['preview']) || (isset($_GET['action']) && $_GET['action'] === 'view');

if ($request_id <= 0) {
    $_SESSION['error'] = 'Invalid request ID specified.';
    redirect('my-requests.php');
}

$stmt = $conn->prepare("SELECT * FROM requests WHERE request_id = ? AND deleted_at IS NULL LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('Database query error.');
}
$stmt->bind_param('i', $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    http_response_code(404);
    $_SESSION['error'] = 'Request record could not be found.';
    redirect('my-requests.php');
}

$can_manage_all = hasPermission('requests.manage') 
    || isAdmin() 
    || isBackOfficeUser() 
    || (isset($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['admin', 'administrator', 'staff', 'coordinator'], true));

$owns_request = ($user_id > 0 && intval($request['user_id']) === $user_id);
if (!$can_manage_all && !$owns_request) {
    http_response_code(403);
    $_SESSION['error'] = 'Access denied. You do not have permission to access this certificate.';
    redirect('my-requests.php');
}

$req_status = strtolower(trim((string)($request['status'] ?? '')));
if ($req_status !== 'completed' && !$can_manage_all) {
    $_SESSION['error'] = 'Certificate is only available once your request is Completed by the parish office.';
    redirect('view-request.php?id=' . $request_id);
}

// Locate newest admin-uploaded certificate file
$cert_file_path = trim((string)($request['certificate_file_path'] ?? ''));
$cert_file_name = trim((string)($request['certificate_file_name'] ?? ''));

// Fallback to newest request_documents record if not on requests row
if ($cert_file_path === '') {
    $doc_stmt = $conn->prepare("
        SELECT file_path, original_name 
        FROM request_documents 
        WHERE request_id = ? 
          AND document_type IN ('released_certificate', 'admin_file') 
          AND deleted_at IS NULL 
        ORDER BY uploaded_at DESC, document_id DESC 
        LIMIT 1
    ");
    if ($doc_stmt) {
        $doc_stmt->bind_param('i', $request_id);
        $doc_stmt->execute();
        $rel_doc = $doc_stmt->get_result()->fetch_assoc();
        $doc_stmt->close();
        if ($rel_doc) {
            $cert_file_path = trim((string)($rel_doc['file_path'] ?? ''));
            $cert_file_name = trim((string)($rel_doc['original_name'] ?? ''));
        }
    }
}

if ($cert_file_path === '') {
    $_SESSION['error'] = 'No certificate file has been uploaded yet by the parish office. Please wait for parish staff to upload your certificate.';
    redirect('view-request.php?id=' . $request_id);
}

$real_file = resolveSecureFilePath($cert_file_path);
if (!$real_file || !is_file($real_file)) {
    $_SESSION['error'] = 'The certificate file is currently unavailable on the server. Please contact the parish office.';
    redirect('view-request.php?id=' . $request_id);
}

// Determine original filename
$original_filename = ($cert_file_name !== '') ? $cert_file_name : basename($real_file);
$ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));

$ext_map = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp'
];

$mime_type = $ext_map[$ext] ?? 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected = finfo_file($finfo, $real_file);
    finfo_close($finfo);
    if ($detected && $detected !== 'application/octet-stream') {
        $mime_type = $detected;
    }
} elseif (function_exists('mime_content_type')) {
    $detected = mime_content_type($real_file);
    if ($detected && $detected !== 'application/octet-stream') {
        $mime_type = $detected;
    }
}

// Audit log
writeAuditLog(
    $conn,
    (int)$_SESSION['user_id'],
    $is_inline_view ? 'VIEW_CERTIFICATE' : 'DOWNLOAD_CERTIFICATE',
    'requests',
    $request_id,
    null,
    null
);

// Clear output buffers
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// Clean filename for HTTP header without quotes or newlines, but keeping original name & spaces
$safe_filename = str_replace(['"', "\r", "\n", "\0"], '', basename($original_filename));
$encoded_filename = rawurlencode($safe_filename);
$disposition_type = $is_inline_view ? 'inline' : 'attachment';

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . (string) filesize($real_file));
header('Content-Disposition: ' . $disposition_type . '; filename="' . $safe_filename . '"; filename*=UTF-8\'\'' . $encoded_filename);
header('Cache-Control: private, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($real_file);
exit;
