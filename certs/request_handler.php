<?php
session_start(); 
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: request_form.php'); exit; }

initSession();
if (isSessionExpired()) {
    logoutUser();
}
requireAuth();
requireParishioner();

$user_id = getCurrentUserId();
$certificate_type = trim($_POST['certificate_type'] ?? '');
$release_method = trim($_POST['release_method'] ?? 'walkin');
$request_notes = trim($_POST['submission_notes'] ?? '');

// create request (include notes)
$stmt = $conn->prepare("INSERT INTO Certificate_Requests (user_id, certificate_type, release_method, request_status, request_notes) VALUES (?, ?, ?, 'Pending Review', ?)");
$stmt->bind_param('isss', $user_id, $certificate_type, $release_method, $request_notes);
if (!$stmt->execute()) { error_log($stmt->error); die('Failed to create request'); }
$request_id = $stmt->insert_id; $stmt->close();

$upload_base = __DIR__ . '/../uploads/requests/' . $request_id;
if (!is_dir($upload_base)) mkdir($upload_base, 0755, true);

$allowed_req = ['application/pdf','image/jpeg','image/png'];
$maxSize = 5 * 1024 * 1024;

// handle requirement files
// capture requirement names and notes
$reqFiles = $_FILES['requirement_files'] ?? null;
$reqNames = $_POST['requirement_names'] ?? [];
if ($reqFiles && is_array($reqFiles['name'])){
  for ($i=0;$i<count($reqFiles['name']);$i++){
    if ($reqFiles['error'][$i] !== UPLOAD_ERR_OK) continue;
    $tmp = $reqFiles['tmp_name'][$i]; $orig = basename($reqFiles['name'][$i]);
    $type = mime_content_type($tmp) ?: $reqFiles['type'][$i];
    if (!in_array($type, $allowed_req)) continue;
    if (filesize($tmp) > $maxSize) continue;
    $ext = pathinfo($orig, PATHINFO_EXTENSION); $safe = uniqid() . '.' . $ext; $dest = $upload_base . '/' . $safe;
    if (!move_uploaded_file($tmp, $dest)) continue;
    $path_db = 'uploads/requests/' . $request_id . '/' . $safe;
    $req_name = isset($reqNames[$i]) ? trim($reqNames[$i]) : null;
    $s = $conn->prepare("INSERT INTO Request_Requirements (request_id, file_name, file_path, requirement_name) VALUES (?, ?, ?, ?)");
    $s->bind_param('isss', $request_id, $orig, $path_db, $req_name); $s->execute(); $s->close();
  }
}

// handle receipts if online
if ($release_method === 'online'){
  $payment_method = $_POST['payment_method'] ?? '';
  $amount = floatval($_POST['amount'] ?? 0);
  $reference_number = trim($_POST['reference_number'] ?? '');
  $receiptFiles = $_FILES['receipt_files'] ?? null;
  if ($receiptFiles && is_array($receiptFiles['name'])){
    for ($i=0;$i<count($receiptFiles['name']);$i++){
      if ($receiptFiles['error'][$i] !== UPLOAD_ERR_OK) continue;
      $tmp = $receiptFiles['tmp_name'][$i]; $orig = basename($receiptFiles['name'][$i]);
      $type = mime_content_type($tmp) ?: $receiptFiles['type'][$i];
      if (!in_array($type, array_merge($allowed_req, ['application/pdf']))) continue;
      if (filesize($tmp) > $maxSize) continue;
      $ext = pathinfo($orig, PATHINFO_EXTENSION); $safe = uniqid() . '.' . $ext; $dest = $upload_base . '/' . $safe;
      if (!move_uploaded_file($tmp, $dest)) continue;
      $path_db = 'uploads/requests/' . $request_id . '/' . $safe;
      $s = $conn->prepare("INSERT INTO Payment_Receipts (request_id, payment_method, reference_number, transaction_number, amount, receipt_file, verification_status) VALUES (?, ?, ?, ?, ?, ?, 'Pending Verification')");
      $txn = ''; $s->bind_param('isssds', $request_id, $payment_method, $reference_number, $txn, $amount, $path_db); $s->execute(); $s->close();
    }
  } else {
    // still insert a Payment_Receipts record without file to track payment
    $s = $conn->prepare("INSERT INTO Payment_Receipts (request_id, payment_method, reference_number, amount, verification_status) VALUES (?, ?, ?, ?, 'Pending Verification')");
    $s->bind_param('issd', $request_id, $payment_method, $reference_number, $amount); $s->execute(); $s->close();
  }
  // update request status to Payment Verification
  $conn->query("UPDATE Certificate_Requests SET request_status='Payment Verification' WHERE request_id = " . intval($request_id));
}

// notify admins
$msg = 'New certificate request #' . $request_id . ' by user ' . $user_id;
$stmt = $conn->prepare("INSERT INTO Notifications (user_id, message) VALUES (0, ?)"); $stmt->bind_param('s', $msg); $stmt->execute(); $stmt->close();

header('Location: /certs/request_submitted.php?id=' . $request_id);
exit;
