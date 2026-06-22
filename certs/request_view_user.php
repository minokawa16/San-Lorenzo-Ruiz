<?php
session_start(); 
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/auth.php';

initSession();
if (isSessionExpired()) {
    logoutUser();
}
requireAuth();

$user_id = getCurrentUserId();
$id = intval($_GET['id'] ?? 0);

// Check permission
if (!canViewRequest($conn, $id)) {
    echo 'Request not found or access denied.'; 
    exit;
}

$req = $conn->query("SELECT * FROM Certificate_Requests WHERE request_id = " . $id . " AND user_id = " . $user_id)->fetch_assoc();
if (!$req) { echo 'Request not found or access denied.'; exit; }
$requirements = $conn->query("SELECT * FROM Request_Requirements WHERE request_id = " . $id);
$payments = $conn->query("SELECT * FROM Payment_Receipts WHERE request_id = " . $id);
?>
<!doctype html><html><head><meta charset="utf-8"><title>My Request</title></head><body>
<h2>Request #<?=$id?></h2>
<p>Type: <?=htmlspecialchars($req['certificate_type'])?> | Release: <?=$req['release_method']?> | Status: <?=$req['request_status']?></p>
<h3>Requirement Files</h3>
<?php while($f = $requirements->fetch_assoc()): ?>
  <div>
    <strong><?=htmlspecialchars($f['requirement_name'] ?: $f['file_name'])?></strong>
    - <?=htmlspecialchars($f['file_name'])?> - <a href="/secure_file.php?path=<?=urlencode($f['file_path'])?>">Preview / Download</a>
    <?php if (!in_array($req['request_status'], ['Approved','Ready for Pickup','Ready for Download','Completed'])): ?>
      <form method="post" action="/certs/replace_file.php" enctype="multipart/form-data" style="margin-top:8px;">
        <input type="hidden" name="type" value="request">
        <input type="hidden" name="file_id" value="<?=$f['requirement_id']?>">
        <label>Replace file: <input type="file" name="new_file" accept=".pdf,image/*" required></label>
        <label>Requirement name: <input type="text" name="requirement_name" value="<?=htmlspecialchars($f['requirement_name'])?>"></label>
        <button type="submit">Replace</button>
      </form>
    <?php endif; ?>
  </div>
<?php endwhile; ?>
<?php if (!in_array($req['request_status'], ['Approved','Ready for Pickup','Ready for Download','Completed'])): ?>
  <h4>Add More Requirement Files</h4>
  <form method="post" action="/certs/add_request_files.php" enctype="multipart/form-data">
    <input type="hidden" name="request_id" value="<?=$id?>">
    <label>Files: <input type="file" name="requirement_files[]" multiple accept=".pdf,image/*" required></label>
    <label>Requirement names (one per file): <input type="text" name="requirement_names[]" placeholder="Requirement name"></label>
    <button type="submit">Add Files</button>
  </form>
<?php endif; ?>

<h3>Payment Receipts</h3>
<?php while($p = $payments->fetch_assoc()): ?>
  <div>Method: <?=$p['payment_method']?> | Amount: <?=$p['amount']?> | Status: <?=$p['verification_status']?>
    <?php if (!empty($p['receipt_file'])): ?> - <a href="/secure_file.php?path=<?=urlencode($p['receipt_file'])?>">Receipt</a><?php endif; ?>
  </div>
<?php endwhile; ?>

</body></html>
