<?php
session_start(); require_once __DIR__ . '/../database/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }
if (!isset($_SESSION['user_id'])) { header('Location: /auth/login.php'); exit; }

$type = $_POST['type'] ?? 'request'; // 'request' or 'submission'
$file_id = intval($_POST['file_id'] ?? 0);
$req_name = trim($_POST['requirement_name'] ?? '');
$user_id = intval($_SESSION['user_id']);

if ($type === 'request') {
  $stmt = $conn->prepare("SELECT rr.*, cr.user_id FROM Request_Requirements rr JOIN Certificate_Requests cr ON rr.request_id = cr.request_id WHERE rr.requirement_id = ? LIMIT 1");
  $stmt->bind_param('i', $file_id); $stmt->execute(); $res = $stmt->get_result(); $row = $res->fetch_assoc(); $stmt->close();
  if (!$row) { echo 'File not found'; exit; }
  $owner = $row['user_id'];
  if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    if ($owner != $user_id) { echo 'Access denied'; exit; }
  }
  $uploadDir = __DIR__ . '/..';
  $fileField = 'new_file';
  if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) { echo 'No file uploaded'; exit; }
  $tmp = $_FILES[$fileField]['tmp_name']; $orig = basename($_FILES[$fileField]['name']);
  $ext = pathinfo($orig, PATHINFO_EXTENSION); $safe = uniqid() . '.' . $ext;
  $destPath = dirname(__DIR__) . '/' . dirname($row['file_path']) . '/' . $safe;
  if (!is_dir(dirname($destPath))) mkdir(dirname($destPath), 0755, true);
  if (!move_uploaded_file($tmp, $destPath)) { echo 'Upload failed'; exit; }
  // remove old file if exists
  $old = realpath(__DIR__ . '/../' . $row['file_path']); if ($old && file_exists($old)) @unlink($old);
  $new_db_path = dirname($row['file_path']) . '/' . $safe;
  $s = $conn->prepare("UPDATE Request_Requirements SET file_name = ?, file_path = ?, requirement_name = ?, uploaded_at = NOW() WHERE requirement_id = ?");
  $s->bind_param('sssi', $orig, $new_db_path, $req_name, $file_id); $s->execute(); $s->close();
  // audit
  $a = $conn->prepare("INSERT INTO Audit_Logs (user_id, action, details) VALUES (?, 'replace_request_file', ?)"); $details = 'requirement_id=' . $file_id; $a->bind_param('is', $user_id, $details); $a->execute(); $a->close();
  header('Location: /certs/request_view_user.php?id=' . intval($row['request_id'])); exit;

} else {
  // submission type
  $stmt = $conn->prepare("SELECT * FROM Requirement_Files WHERE file_id = ? LIMIT 1");
  $stmt->bind_param('i',$file_id); $stmt->execute(); $res = $stmt->get_result(); $row = $res->fetch_assoc(); $stmt->close();
  if (!$row) { echo 'File not found'; exit; }
  // find submission owner
  $ownerRow = $conn->query("SELECT user_id FROM Requirements_Submissions WHERE submission_id = " . intval($row['submission_id']))->fetch_assoc();
  $owner = $ownerRow['user_id'] ?? 0;
  if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    if ($owner != $user_id) { echo 'Access denied'; exit; }
  }
  $fileField = 'new_file';
  if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) { echo 'No file uploaded'; exit; }
  $tmp = $_FILES[$fileField]['tmp_name']; $orig = basename($_FILES[$fileField]['name']);
  $ext = pathinfo($orig, PATHINFO_EXTENSION); $safe = uniqid() . '.' . $ext;
  $destPath = dirname(__DIR__) . '/' . dirname($row['file_path']) . '/' . $safe;
  if (!is_dir(dirname($destPath))) mkdir(dirname($destPath), 0755, true);
  if (!move_uploaded_file($tmp, $destPath)) { echo 'Upload failed'; exit; }
  $old = realpath(__DIR__ . '/../' . $row['file_path']); if ($old && file_exists($old)) @unlink($old);
  $new_db_path = dirname($row['file_path']) . '/' . $safe;
  $s = $conn->prepare("UPDATE Requirement_Files SET file_name = ?, file_path = ?, requirement_name = ?, uploaded_at = NOW() WHERE file_id = ?");
  $s->bind_param('sssi', $orig, $new_db_path, $req_name, $file_id); $s->execute(); $s->close();
  $a = $conn->prepare("INSERT INTO Audit_Logs (user_id, action, details) VALUES (?, 'replace_submission_file', ?)"); $details = 'file_id=' . $file_id; $a->bind_param('is', $user_id, $details); $a->execute(); $a->close();
  header('Location: /certs/submit_success.php?submission=' . intval($row['submission_id'])); exit;
}
