<?php
/**
 * Announcement Attachment Module - Securely serves uploaded announcement files.
 */

include __DIR__ . '/includes/session.php';
include __DIR__ . '/database/config.php';
include __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/services/AnnouncementService.php';

ensureAnnouncementAttachmentSchema($conn);

$announcement_id = intval($_GET['id'] ?? 0);
if ($announcement_id <= 0) {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = $conn->prepare("SELECT announcement_id, title, status, lifecycle_status, deleted_at, audience_type, attachment_path, attachment_original_name, attachment_mime_type FROM announcements WHERE announcement_id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('Unable to load attachment.');
}

$stmt->bind_param('i', $announcement_id);
$stmt->execute();
$announcement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$announcement || empty($announcement['attachment_path']) || !empty($announcement['deleted_at'])) {
    http_response_code(404);
    exit('Attachment not found.');
}

$current_user_id = intval($_SESSION['user_id'] ?? 0);
$is_admin = hasPermission('announcements.manage') || (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff'], true));

// Permission check: admins can always view; published public announcements can be viewed by all; targeted announcements require login
if (!$is_admin) {
    $is_public = empty($announcement['audience_type']) || $announcement['audience_type'] === 'everyone';
    $is_active = in_array($announcement['status'], ['active', 'published'], true) || in_array($announcement['lifecycle_status'], ['published', 'active'], true);
    
    if (!$is_public && !isLoggedIn()) {
        http_response_code(403);
        exit('Login required.');
    }
    
    if (!$is_public && isLoggedIn()) {
        $annService = new AnnouncementService($conn);
        if (!$annService->canView($announcement_id, $current_user_id)) {
            http_response_code(403);
            exit('Access denied to this announcement attachment.');
        }
    }
}

$rel_path = ltrim(str_replace(['../', '..\\'], '', (string) $announcement['attachment_path']), '/\\');
$possible_paths = [
    __DIR__ . DIRECTORY_SEPARATOR . $rel_path,
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements' . DIRECTORY_SEPARATOR . basename($rel_path),
    dirname(__DIR__) . DIRECTORY_SEPARATOR . $rel_path
];

$file_path = null;
foreach ($possible_paths as $candidate) {
    if (is_file($candidate)) {
        $file_path = $candidate;
        break;
    }
}

if (!$file_path || !is_file($file_path)) {
    http_response_code(404);
    exit('Attachment file not found on disk.');
}

$mime_type = $announcement['attachment_mime_type'] ?: 'application/octet-stream';
$filename = $announcement['attachment_original_name'] ?: basename($file_path);
$disposition = isAnnouncementImageAttachment($mime_type) || $mime_type === 'application/pdf' ? 'inline' : 'attachment';

if ($current_user_id > 0) {
    writeAuditLog($conn, $current_user_id, 'DOWNLOAD_ANNOUNCEMENT_ATTACHMENT', 'announcements', $announcement_id, null, null);
}

secureStreamFile($file_path, $mime_type, $filename, $disposition === 'inline');
