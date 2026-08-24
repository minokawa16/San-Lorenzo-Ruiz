<?php
/**
 * Protected preview/download endpoint for uploaded certificate template files.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
include '../includes/CertificateTemplateManager.php';

requireAdmin();
requirePermission('certificates.manage');

$template_id = intval($_GET['id'] ?? 0);
$template = getCertificateTemplateById($conn, $template_id);
if (!$template || $template['status'] === 'deleted') {
    http_response_code(404);
    echo 'Template not found.';
    exit;
}

$path = dirname(__DIR__) . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template['file_path']);
$real_upload_dir = realpath(CERTIFICATE_TEMPLATE_UPLOAD_DIR);
$real_path = realpath($path);
if (!$real_upload_dir || !$real_path || strpos($real_path, $real_upload_dir) !== 0 || !is_file($real_path)) {
    http_response_code(404);
    echo 'Template file not found.';
    exit;
}

$download = !empty($_GET['download']);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $template['file_original_name']);
writeAuditLog($conn, (int) $_SESSION['user_id'], 'DOWNLOAD_CERTIFICATE_TEMPLATE', 'certificate_templates', $template_id, null, null);
secureStreamFile($real_path, (string) $template['mime_type'], $filename, !$download);
exit;
?>
