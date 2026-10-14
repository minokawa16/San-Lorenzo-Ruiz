<?php
/**
 * Legacy Certificate Generator Module - Deprecated.
 * Certificate generation has been migrated directly into each sacramental records table.
 * Redirects visitors and legacy links to the records management page.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/helpers.php';

// If POST request with CSRF token and cert_type/record_id, maintain backward compatibility
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($_POST['cert_type']) || !empty($_POST['registry']))) {
    requireValidCsrfToken();
    require_once __DIR__ . '/generate-certificate.php';
    exit;
}

$certType = strtolower(trim((string)($_GET['cert_type'] ?? ($_GET['type'] ?? ''))));

$registryMap = [
    'baptism' => 'baptism-records.php',
    'baptism_certification' => 'baptism-records.php',
    'communion' => 'communion-records.php',
    'first_communion' => 'communion-records.php',
    'first_communion_certification' => 'communion-records.php',
    'confirmation' => 'confirmation-records.php',
    'confirmation_certification' => 'confirmation-records.php',
    'marriage' => 'marriage-records.php',
    'marriage_certification' => 'marriage-records.php',
    'funeral' => 'funeral-records.php',
    'funeral_certification' => 'funeral-records.php',
];

$targetPage = $registryMap[$certType] ?? 'manage-records.php';

$query = $_GET;
unset($query['cert_type'], $query['type']);
$queryString = !empty($query) ? '?' . http_build_query($query) : '';

header('Location: ' . $targetPage . $queryString, true, 302);
exit;
