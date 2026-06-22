<?php
session_start(); require_once __DIR__ . '/../database/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }
if (!isset($_SESSION['user_id'])) { header('Location: /auth/login.php'); exit; }

$request_id = intval($_POST['request_id'] ?? 0);
$user_id = intval($_SESSION['user_id']);

$req = $conn->query("SELECT * FROM Certificate_Requests WHERE request_id = " . $request_id)->fetch_assoc();
if (!$req) { echo 'Request not found'; exit; }
if ($req['user_id'] != $user_id && (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin'])) { echo 'Access denied'; exit; }

$upload_base = __DIR__ . '/../uploads/requests/' . $request_id;
if (!is_dir($upload_base)) mkdir($upload_base, 0755, true);

$allowed = ['application/pdf','image/jpeg','image/png']; $maxSize = 5 * 1024 * 1024;
$files = $_FILES['requirement_files'] ?? null;
$reqNames = $_POST['requirement_names'] ?? [];
if ($files && is_array($files['name'])){
  for ($i=0;$i<count($files['name']);$i++){
    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
    $tmp = $files['tmp_name'][$i]; $orig = basename($files['name'][$i]);
    $type = mime_content_type($tmp) ?: $files['type'][$i];
    if (!in_array($type, $allowed)) continue;
    if (filesize($tmp) > $maxSize) continue;
    $ext = pathinfo($orig, PATHINFO_EXTENSION); $safe = uniqid() . '.' . $ext; $dest = $upload_base . '/' . $safe;
    if (!move_uploaded_file($tmp, $dest)) continue;
    $path_db = 'uploads/requests/' . $request_id . '/' . $safe;
    $req_name = isset($reqNames[$i]) ? trim($reqNames[$i]) : null;
    $s = $conn->prepare("INSERT INTO Request_Requirements (request_id, file_name, file_path, requirement_name) VALUES (?, ?, ?, ?)");
    $s->bind_param('isss', $request_id, $orig, $path_db, $req_name); $s->execute(); $s->close();
  }
}

header('Location: /certs/request_view_user.php?id=' . $request_id);
exit;
