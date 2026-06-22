<?php
session_start(); 
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/auth.php';

initSession();
if (isSessionExpired()) {
    logoutUser();
}
requireAuth();
requireParishioner();

$certificate_types = ['Baptism Certificate','Confirmation Certificate','Marriage Certificate'];
?>
<!doctype html><html><head><meta charset="utf-8"><title>Request Certificate</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head><body>
<h2>Request Certificate</h2>
<form id="requestForm" method="post" enctype="multipart/form-data" action="request_handler.php">
  <label>Certificate Type</label>
  <select name="certificate_type" required>
    <?php foreach($certificate_types as $ct): ?><option value="<?=htmlspecialchars($ct)?>"><?=htmlspecialchars($ct)?></option><?php endforeach; ?>
  </select>

  <label>Release Method</label>
  <select name="release_method" id="release_method" required>
    <option value="walkin">Walk-In Pickup</option>
    <option value="online">Online Release</option>
  </select>

  <div id="requirements_section">
    <label>Upload Requirements (PDF, JPG, JPEG, PNG) - max 5MB each</label>
    <div id="dropZone" style="border:2px dashed #ccc;padding:12px;">
      <input type="file" id="fileInput" name="requirement_files[]" multiple accept=".pdf,image/*">
      <p>Drag & drop requirement files here or click to choose.</p>
      <div id="previews"></div>
    </div>
    <div id="requirementNames"></div>
    <label for="submissionNotes">Additional Notes</label>
    <textarea id="submissionNotes" name="submission_notes" rows="3" style="width:100%;"></textarea>
  </div>

  <div id="payment_section" style="display:none;">
    <h3>Payment</h3>
    <p>Choose payment method and upload proof after paying.</p>
    <label>Payment Method</label>
    <select name="payment_method">
      <option value="gcash">GCash</option>
      <option value="maya">Maya</option>
      <option value="online_banking">Online Banking</option>
      <option value="bank_transfer">Bank Transfer</option>
    </select>
    <label>Amount</label>
    <input type="number" step="0.01" name="amount">
    <label>Reference Number</label>
    <input type="text" name="reference_number">
    <label>Receipt Files (JPG, PNG, PDF)</label>
    <input type="file" name="receipt_files[]" multiple accept=".pdf,image/*">
  </div>

  <button type="submit">Submit Request</button>
</form>

<script src="/assets/js/requirements.js"></script>
<script>
document.getElementById('release_method').addEventListener('change', function(){
  document.getElementById('payment_section').style.display = this.value==='online' ? 'block' : 'none';
});
</script>
</body></html>
