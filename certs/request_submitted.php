<?php
require_once __DIR__ . '/../database/config.php';
$id = intval($_GET['id'] ?? 0);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Request Submitted</title></head><body>
<h2>Request Submitted</h2>
<p>Your certificate request (ID: <?=$id?>) was submitted. Admin will review and notify you.</p>
<p><a href="/certs/downloads.php">Go to Download Center</a></p>
</body></html>
