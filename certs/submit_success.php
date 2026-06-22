<?php
require_once __DIR__ . '/../database/config.php';
$submission = intval($_GET['submission'] ?? 0);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Submission Received</title></head><body>
<h2>Submission Received</h2>
<p>Your requirements submission (ID: <?=$submission?>) was received and is pending admin review.</p>
<p>You will be notified once the requirements are approved or if additional documents are requested.</p>
</body></html>
