<?php
/** Authenticated delivery/download for protected certificate layout images. */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/database/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/CertificateTemplateManager.php';

requireAdmin();
requirePermission('certificates.manage');

$asset = basename(trim((string) ($_GET['asset'] ?? '')));
if ($asset === '' || !preg_match('/^[a-z0-9_-]+\.(?:png|jpe?g|webp)$/i', $asset)) {
    http_response_code(404);
    exit('Logo not found.');
}

$baseDirectory = realpath(CERTIFICATE_LAYOUT_ASSET_DIR);
$filePath = realpath(CERTIFICATE_LAYOUT_ASSET_DIR . DIRECTORY_SEPARATOR . $asset);
if (!$baseDirectory || !$filePath || !str_starts_with($filePath, $baseDirectory . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    http_response_code(404);
    exit('Logo not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';
if (!array_key_exists($mime, certificateLayoutAllowedMimes())) {
    http_response_code(415);
    exit('Unsupported logo format.');
}

$download = filter_var($_GET['download'] ?? false, FILTER_VALIDATE_BOOLEAN);
if ($download) {
    writeAuditLog($conn, (int) $_SESSION['user_id'], 'DOWNLOAD_CERTIFICATE_LAYOUT_IMAGE', 'certificate_layouts', 0, null, $asset);
}
secureStreamFile($filePath, $mime, $asset, !$download);
