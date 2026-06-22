<?php
session_start(); require_once __DIR__ . '/database/config.php';
// path expected like uploads/requirements/123/file.pdf
$path = $_GET['path'] ?? '';
if (!$path) { http_response_code(400); exit; }

$full = realpath(__DIR__ . '/' . $path);
// ensure file is inside uploads directory
$uploadsRoot = realpath(__DIR__ . '/uploads');
if (!$full || strpos($full, $uploadsRoot) !== 0) { http_response_code(404); exit; }

// Basic permission: admin or owner
$allowed = false;
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) $allowed = true;
else {
  // find submission owner
  $stmt = $conn->prepare("SELECT rs.user_id FROM Requirement_Files rf JOIN Requirements_Submissions rs ON rf.submission_id=rs.submission_id WHERE rf.file_path = ? LIMIT 1");
  $stmt->bind_param('s', $path); $stmt->execute(); $res = $stmt->get_result(); $r = $res->fetch_assoc();
  $owner = $r['user_id'] ?? 0; if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $owner) $allowed = true;
}

if (!$allowed) { http_response_code(403); exit; }

$mime = mime_content_type($full);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($full) . '"');
readfile($full);
exit;
