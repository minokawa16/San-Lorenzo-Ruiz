<?php
/**
 * Confirmation Certificate Studio - Deprecated Route
 * Permanently redirected (HTTP 301) to Certificate Layouts / View Certificate.
 */

require_once __DIR__ . '/../includes/session.php';

$recordId = intval($_GET['id'] ?? 0);

if ($recordId > 0) {
    header('Location: view-certificate.php?type=confirmation&id=' . $recordId, true, 301);
    exit;
}

header('Location: certificate-templates.php', true, 301);
exit;
