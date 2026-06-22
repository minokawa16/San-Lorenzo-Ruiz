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

$user_id = getCurrentUserId();

$certificate_types = ['Baptism Certificate','Confirmation Certificate','Marriage Certificate'];

?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Submit Requirements</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head><body>
<h2>Submit Requirements</h2>
<form id="reqForm" method="post" enctype="multipart/form-data" action="upload_handler.php">
  <label for="certificate_type">Certificate Type</label>
  <select name="certificate_type" id="certificate_type" required>
    <?php foreach($certificate_types as $ct): ?>
      <option value="<?=htmlspecialchars($ct)?>"><?=htmlspecialchars($ct)?></option>
    <?php endforeach; ?>
  </select>

  <div id="requirementsList">
    <p>Select certificate type to view required documents after selecting.</p>
  </div>

  <label>Upload Documents (PDF, JPG, JPEG, PNG) - max 5MB each</label>
  <div id="dropZone" style="border:2px dashed #ccc;padding:20px;">
    <input type="file" id="fileInput" name="files[]" multiple accept=".pdf,image/*">
    <p>Drag & drop files here, or click to browse.</p>
    <div id="previews"></div>
  </div>
  <div id="requirementNames"></div>
  <label for="submissionNotes">Additional Notes</label>
  <textarea id="submissionNotes" name="submission_notes" rows="3" style="width:100%;"></textarea>

  <input type="hidden" name="user_id" value="<?=$user_id?>">
  <button type="submit">Submit Requirements</button>
</form>

<script src="/assets/js/requirements.js"></script>
</body></html>
