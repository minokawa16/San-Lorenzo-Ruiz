<?php
/**
 * Canonical Certificate Generation Endpoint
 * Handles on-demand certificate generation from sacramental registry tables.
 *
 * Parameters:
 *   registry:  baptism | communion | confirmation | marriage | funeral
 *   record_id: integer ID of the record
 *   type:      original | certification
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';

// 1. Authorization: Only Admin and Parish Secretary / Staff are allowed
requireAuthentication();

$allowedRoles = ['admin', 'administrator', 'secretary', 'parish_secretary', 'staff', 'parish_staff', 'records_clerk'];
$userRoles = array_map('strtolower', userRoleKeys());
$sessionRole = strtolower((string)($_SESSION['role'] ?? ''));

$isAuthorized = false;
foreach ($allowedRoles as $r) {
    if (in_array($r, $userRoles, true) || $sessionRole === $r || normalizeUserRole($sessionRole) === $r) {
        $isAuthorized = true;
        break;
    }
}
if (!$isAuthorized && (hasPermission('certificates.manage') || hasPermission('admin.access') || hasPermission('records.manage'))) {
    $isAuthorized = true;
}

if (!$isAuthorized) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden - Access Denied</title>
        <link rel="stylesheet" href="../assets/css/holy-theme.css">
    </head>
    <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #FAF8F5; color: #1E293B; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px;">
        <div style="background: #FFFFFF; border: 1px solid #E2D8C3; border-radius: 12px; padding: 32px; max-width: 480px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.06);">
            <div style="width: 56px; height: 56px; background: #FEE2E2; color: #DC2626; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 16px;">
                <i class="fas fa-lock"></i>
            </div>
            <h2 style="margin: 0 0 12px 0; color: #0F172A; font-size: 1.4rem;">403 Forbidden</h2>
            <p style="color: #64748B; margin-bottom: 24px; font-size: 0.95rem; line-height: 1.5;">
                You do not have permission to generate parish certificates. Only administrators and authorized parish secretaries may issue official church documents.
            </p>
            <a href="manage-records.php" style="display: inline-block; padding: 10px 22px; background: #2E3A2D; color: #FFFFFF; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem;">
                Return to Records
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 2. Normalize and validate input parameters
$rawRegistry = strtolower(trim((string)($_GET['registry'] ?? ($_POST['registry'] ?? ($_GET['cert_type'] ?? ($_POST['cert_type'] ?? ''))))));
$recordId = intval($_GET['record_id'] ?? ($_POST['record_id'] ?? ($_GET['id'] ?? ($_POST['id'] ?? 0))));
$rawType = strtolower(trim((string)($_GET['type'] ?? ($_POST['type'] ?? ''))));

// Registry normalization
if ($rawRegistry === 'first_communion' || $rawRegistry === 'first communion' || $rawRegistry === 'first_communion_records') {
    $registry = 'communion';
} elseif ($rawRegistry === 'burial' || $rawRegistry === 'death' || $rawRegistry === 'funeral_records') {
    $registry = 'funeral';
} elseif ($rawRegistry === 'baptism_records') {
    $registry = 'baptism';
} elseif ($rawRegistry === 'confirmation_records') {
    $registry = 'confirmation';
} elseif ($rawRegistry === 'marriage_records') {
    $registry = 'marriage';
} else {
    $registry = $rawRegistry;
}

// Check if registry was passed as full cert_type like "baptism_certification"
if ($rawRegistry === 'baptism_certification') { $registry = 'baptism'; $rawType = 'certification'; }
if ($rawRegistry === 'first_communion_certification') { $registry = 'communion'; $rawType = 'certification'; }
if ($rawRegistry === 'confirmation_certification') { $registry = 'confirmation'; $rawType = 'certification'; }
if ($rawRegistry === 'marriage_certification') { $registry = 'marriage'; $rawType = 'certification'; }
if ($rawRegistry === 'funeral_certification') { $registry = 'funeral'; $rawType = 'certification'; }

$validRegistries = ['baptism', 'communion', 'confirmation', 'marriage', 'funeral'];
if (!in_array($registry, $validRegistries, true) || $recordId <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:30px;text-align:center;'><h3>400 Bad Request</h3><p>Invalid or missing registry parameters.</p><p><a href='manage-records.php'>Back to Records</a></p></body></html>";
    exit;
}

// Type normalization: 'original' or 'certification'
if (empty($rawType)) {
    $rawType = 'original';
}
if ($rawType !== 'original' && $rawType !== 'certification') {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:30px;text-align:center;'><h3>400 Bad Request</h3><p>Invalid certificate type. Must be 'original' or 'certification'.</p></body></html>";
    exit;
}

// 3. Strict Server-Side Rule: Marriage and Funeral must NEVER be offered or accepted as Original
if ($rawType === 'original' && in_array($registry, ['marriage', 'funeral'], true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden - Certificate Type Not Available</title>
        <link rel="stylesheet" href="../assets/css/holy-theme.css">
    </head>
    <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #FAF8F5; color: #1E293B; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px;">
        <div style="background: #FFFFFF; border: 1px solid #E2D8C3; border-radius: 12px; padding: 32px; max-width: 480px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.06);">
            <div style="width: 56px; height: 56px; background: #FEF3C7; color: #D97706; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 16px;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h2 style="margin: 0 0 12px 0; color: #0F172A; font-size: 1.35rem;">Certification Only</h2>
            <p style="color: #64748B; margin-bottom: 24px; font-size: 0.95rem; line-height: 1.5;">
                <strong><?php echo htmlspecialchars(ucfirst($registry)); ?></strong> records are issued as <strong>Certification</strong> only. Original certificates are not issued for <?php echo htmlspecialchars($registry); ?> registers.
            </p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <a href="generate-certificate.php?registry=<?php echo urlencode($registry); ?>&record_id=<?php echo (int)$recordId; ?>&type=certification" style="display: inline-block; padding: 10px 18px; background: #2E3A2D; color: #FFFFFF; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem;">
                    Generate Certification
                </a>
                <a href="<?php echo htmlspecialchars($registry); ?>-records.php" style="display: inline-block; padding: 10px 18px; background: #F1F5F9; color: #475569; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem;">
                    Back to Registry
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 4. Verify Record Exists and is Active in Database
$tableMap = [
    'baptism' => ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BAP'],
    'communion' => ['table' => 'first_communion_records', 'id' => 'communion_id', 'prefix' => 'COM'],
    'confirmation' => ['table' => 'confirmation_records', 'id' => 'confirmation_id', 'prefix' => 'CON'],
    'marriage' => ['table' => 'marriage_records', 'id' => 'marriage_id', 'prefix' => 'MAR'],
    'funeral' => ['table' => 'funeral_records', 'id' => 'funeral_id', 'prefix' => 'FNC'],
];

$meta = $tableMap[$registry];
$tableName = $meta['table'];
$idCol = $meta['id'];

$stmt = $conn->prepare("SELECT * FROM {$tableName} WHERE {$idCol} = ? AND (status = 'active' OR status IS NULL OR status = '') LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo "Database query error.";
    exit;
}
$stmt->bind_param('i', $recordId);
$stmt->execute();
$res = $stmt->get_result();
$record = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
$stmt->close();

if (!$record) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:30px;text-align:center;'><h3>404 Not Found</h3><p>Active record #{$recordId} not found in the " . htmlspecialchars(ucfirst($registry)) . " registry.</p><p><a href='{$registry}-records.php'>Return to {$registry} records</a></p></body></html>";
    exit;
}

// 5. Map to Existing Template Types
if ($registry === 'baptism') {
    $certType = ($rawType === 'certification') ? 'baptism_certification' : 'baptism';
} elseif ($registry === 'communion') {
    $certType = ($rawType === 'certification') ? 'first_communion_certification' : 'communion';
    $signers = getFirstCommunionSigners($conn, $record);
    if (empty($record['catechist_coordinator'])) $record['catechist_coordinator'] = $signers['catechist_coordinator'];
    if (empty($record['parish_priest']))          $record['parish_priest']          = $signers['parish_priest'];
    if (empty($record['principal']))              $record['principal']              = $signers['principal'];
} elseif ($registry === 'confirmation') {
    $certType = ($rawType === 'certification') ? 'confirmation_certification' : 'confirmation';
} elseif ($registry === 'marriage') {
    $certType = 'marriage_certification';
} elseif ($registry === 'funeral') {
    $certType = 'funeral_certification';
} else {
    $certType = 'baptism';
}

// 6. Save Generated Certificate into Request History (`certificate_issuances`)
$userId = (int)($_SESSION['user_id'] ?? 0);
$recipientName = trim((string)($record['fullname'] ?? ($record['deceased_name'] ?? trim(($record['husband_name'] ?? '') . ' and ' . ($record['wife_name'] ?? '')))));
if ($recipientName === '') {
    $recipientName = 'Parishioner';
}

// Determine unique serial sequence prefix
$certPrefixes = [
    'baptism' => 'BAP',
    'baptism_certification' => 'BCF',
    'communion' => 'COM',
    'first_communion_certificate' => 'COM',
    'first_communion_certification' => 'FCF',
    'confirmation' => 'CON',
    'confirmation_certification' => 'CCF',
    'marriage_certification' => 'MCF',
    'funeral_certification' => 'FNC',
];
$prefix = $certPrefixes[$certType] ?? 'CRT';
$certNumber = sprintf('%s-%s-%05d-%s', $prefix, date('Y'), $recordId, strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)));
$verificationCode = bin2hex(random_bytes(32));

$snapshotData = [
    'schema' => 1,
    'registry' => $registry,
    'issuance_type' => $rawType,
    'certificate_type' => $certType,
    'certificate_number' => $certNumber,
    'issued_to' => $recipientName,
    'issued_by' => $userId,
    'issued_at' => date('c'),
    'record' => $record,
];
$snapshotJson = json_encode($snapshotData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$requestId = !empty($record['request_id']) ? (int)$record['request_id'] : null;

// Insert or update certificate_issuances record so it is cataloged in the request history
$insStmt = $conn->prepare(
    "INSERT INTO certificate_issuances (
        request_id, certificate_type, record_table, record_id,
        certificate_number, verification_code, issued_by, issued_to,
        status, issued_at, drafted_by, drafted_at, certificate_snapshot
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued', NOW(), ?, NOW(), ?)"
);

$newCertificateId = 0;
if ($insStmt) {
    $insStmt->bind_param(
        'ississisis',
        $requestId,
        $certType,
        $tableName,
        $recordId,
        $certNumber,
        $verificationCode,
        $userId,
        $recipientName,
        $userId,
        $snapshotJson
    );
    if ($insStmt->execute()) {
        $newCertificateId = (int)$insStmt->insert_id;
    }
    $insStmt->close();
}

// Log event in certificate_events if table exists
if ($newCertificateId > 0 && schemaTableExists($conn, 'certificate_events')) {
    $evStmt = $conn->prepare("INSERT INTO certificate_events (certificate_id, request_id, actor_user_id, action, reason, created_at) VALUES (?, ?, ?, 'issued', 'Certificate generated from sacramental registry table', NOW())");
    if ($evStmt) {
        $evStmt->bind_param('iii', $newCertificateId, $requestId, $userId);
        $evStmt->execute();
        $evStmt->close();
    }
}

// Complete Audit Log entry
$prettyType = ucwords(str_replace('_', ' ', $certType));
$typeLabel = $rawType === 'original' ? 'Original Certificate' : 'Certification';
$desc = "Generated {$prettyType} ({$typeLabel}) for record #{$recordId} ({$recipientName}) by user #{$userId}.";

createAuditLog(
    $conn,
    $userId,
    'GENERATE_CERTIFICATE',
    $certType,
    $recordId,
    null,
    [
        'registry' => $registry,
        'issuance_type' => $rawType,
        'cert_type' => $certType,
        'recipient' => $recipientName,
        'certificate_number' => $certNumber,
        'certificate_id' => $newCertificateId
    ],
    $desc,
    'CERTIFICATES',
    'INFO'
);

// 7. Store in session and redirect to view-certificate.php
unset($_SESSION['manual_certificate']);
$_SESSION['certificate_data'] = $record;
$_SESSION['cert_type'] = $certType;

$redirectUrl = 'view-certificate.php?id=' . $recordId . '&type=' . urlencode($certType);
if ($requestId) {
    $redirectUrl .= '&request_id=' . $requestId;
}
if ($newCertificateId > 0) {
    $redirectUrl .= '&cert_id=' . $newCertificateId;
}

header('Location: ' . $redirectUrl);
exit;
