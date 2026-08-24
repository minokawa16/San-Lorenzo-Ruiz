<?php
/**
 * Announcement Attachment Module - Securely serves uploaded announcement files to authorized users.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'database/config.php';
include 'includes/helpers.php';
require_once 'services/AnnouncementService.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Login required.');
}

ensureAnnouncementAttachmentSchema($conn);

$announcement_id = intval($_GET['id'] ?? 0);
if ($announcement_id <= 0) {
    http_response_code(404);
    exit('Attachment not found.');
}
if (!(new AnnouncementService($conn))->canView($announcement_id,(int)$_SESSION['user_id']) && !hasPermission('announcements.manage')) { http_response_code(404); exit('Attachment not found.'); }

$stmt = $conn->prepare("SELECT attachment_path, attachment_original_name, attachment_mime_type FROM announcements WHERE announcement_id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('Unable to load attachment.');
}

$stmt->bind_param('i', $announcement_id);
$stmt->execute();
$announcement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$announcement || empty($announcement['attachment_path'])) {
    http_response_code(404);
    exit('Attachment not found.');
}

$base_dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements');
$file_path = realpath(__DIR__ . DIRECTORY_SEPARATOR . $announcement['attachment_path']);
if (!$base_dir || !$file_path || strpos($file_path, $base_dir) !== 0 || !is_file($file_path)) {
    http_response_code(404);
    exit('Attachment not found.');
}

$mime_type = $announcement['attachment_mime_type'] ?: 'application/octet-stream';
$filename = $announcement['attachment_original_name'] ?: basename($file_path);
$disposition = isAnnouncementImageAttachment($mime_type) || $mime_type === 'application/pdf' ? 'inline' : 'attachment';

writeAuditLog($conn, (int) $_SESSION['user_id'], 'DOWNLOAD_ANNOUNCEMENT_ATTACHMENT', 'announcements', $announcement_id, null, null);
secureStreamFile($file_path, $mime_type, $filename, $disposition === 'inline');
