<?php
session_start();
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

initSession();
if (isSessionExpired()) {
    http_response_code(401); 
    echo 'Session expired'; 
    exit;
}
requireAuth();
requireParishioner();

$user_id = getCurrentUserId();
$certificate_type = trim($_POST['certificate_type'] ?? '');
if ($certificate_type === '') { echo 'Certificate type required'; exit; }

$allowed = ['application/pdf','image/jpeg','image/png'];
$maxSize = 5 * 1024 * 1024;

// Create submission record
$submission_notes = trim($_POST['submission_notes'] ?? ''); 
$stmt = $conn->prepare("INSERT INTO Requirements_Submissions (user_id, certificate_type, status, submission_notes) VALUES (?, ?, 'Requirements Pending Review', ?)"); 
$stmt->bind_param('iss', $user_id, $certificate_type, $submission_notes); 
if (!$stmt->execute()) { error_log($stmt->error); echo 'Failed to create submission'; exit; }
$submission_id = $stmt->insert_id;
$stmt->close();

$upload_base = __DIR__ . '/../uploads/requirements/' . $submission_id;
if (!is_dir($upload_base)) mkdir($upload_base, 0755, true);

$files = $_FILES['files'] ?? null;
if ($files && is_array($files['name'])) {
    for ($i=0;$i<count($files['name']);$i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp = $files['tmp_name'][$i];
        $orig = basename($files['name'][$i]);
        $type = mime_content_type($tmp) ?: $files['type'][$i];
        if (!in_array($type, $allowed)) continue;
        if (filesize($tmp) > $maxSize) continue;
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $safe = uniqid() . '.' . $ext;
        $dest = $upload_base . '/' . $safe;
        if (!move_uploaded_file($tmp, $dest)) continue;
        $path_db = 'uploads/requirements/' . $submission_id . '/' . $safe;
        $req_names = $_POST['requirement_names'] ?? [];
        $req_name = isset($req_names[$i]) ? trim($req_names[$i]) : null;
        $stmt = $conn->prepare("INSERT INTO Requirement_Files (submission_id, file_name, file_path, file_type, requirement_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issss', $submission_id, $orig, $path_db, $type, $req_name);
        $stmt->execute(); $stmt->close();
    }
}

// Add notification for admin (simple: user_id 0 reserved for admin alerts)
$msg = 'New requirements submitted (ID: ' . $submission_id . ') by user ' . $user_id;
$stmt = $conn->prepare("INSERT INTO Notifications (user_id, message) VALUES (0, ?)");
$stmt->bind_param('s', $msg); $stmt->execute(); $stmt->close();

// Audit log
$stmt = $conn->prepare("INSERT INTO Audit_Logs (user_id, action, details) VALUES (?, 'submit_requirements', ?)");
$details = 'submission_id=' . $submission_id;
$stmt->bind_param('is', $user_id, $details); $stmt->execute(); $stmt->close();

// Redirect to a status page
header('Location: /certs/submit_success.php?submission=' . $submission_id);
exit;
