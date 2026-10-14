<?php
/**
 * Generate Certificate Compatibility Proxy
 * Forwards legacy calls to canonical generate-certificate.php endpoint.
 */

require_once __DIR__ . '/../includes/session.php';

$type = $_GET['type'] ?? ($_POST['type'] ?? '');
$id = intval($_GET['id'] ?? ($_POST['id'] ?? ($_GET['record_id'] ?? ($_POST['record_id'] ?? 0))));

$registry = 'baptism';
$docType = 'original';

if (strpos($type, 'certification') !== false) {
    $docType = 'certification';
}

if (strpos($type, 'communion') !== false) {
    $registry = 'communion';
} elseif (strpos($type, 'confirmation') !== false) {
    $registry = 'confirmation';
} elseif (strpos($type, 'marriage') !== false) {
    $registry = 'marriage';
    $docType = 'certification';
} elseif (strpos($type, 'funeral') !== false || strpos($type, 'burial') !== false) {
    $registry = 'funeral';
    $docType = 'certification';
} elseif (strpos($type, 'baptism') !== false) {
    $registry = 'baptism';
}

$forwardUrl = 'generate-certificate.php?registry=' . urlencode($registry) . '&record_id=' . $id . '&type=' . urlencode($docType);
header('Location: ' . $forwardUrl, true, 302);
exit;
