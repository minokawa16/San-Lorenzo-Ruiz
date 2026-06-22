<?php
/**
 * Certificate Preview Module - Renders generated sacramental certificates for review, printing, and verification.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('certificates.manage');

if (!isset($_SESSION['certificate_data']) || !isset($_SESSION['cert_type'])) {
    header('Location: certificate-generator.php');
    exit;
}

$data = $_SESSION['certificate_data'];
$cert_type = $_SESSION['cert_type'];

// Cert Column Exists Function - Documents this helper's role in the parish management workflow.
function certColumnExists($conn, $table, $column) {
    $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safe_column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$safe_table` LIKE '$safe_column'");
    return $result && $result->num_rows > 0;
}

// Ensure Certificate Schema Function - Documents this helper's role in the parish management workflow.
function ensureCertificateSchema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS certificate_issuances (
        certificate_id INT PRIMARY KEY AUTO_INCREMENT,
        certificate_type VARCHAR(50) NOT NULL,
        record_table VARCHAR(80) NOT NULL,
        record_id INT NOT NULL,
        certificate_number VARCHAR(40) NOT NULL UNIQUE,
        verification_code VARCHAR(80) NOT NULL UNIQUE,
        issued_by INT NULL,
        issued_to VARCHAR(150) NULL,
        status VARCHAR(30) DEFAULT 'valid',
        issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_certificate_record (record_table, record_id),
        INDEX idx_certificate_status (status)
    )");

    $optional_columns = [
        'book_no' => "ALTER TABLE baptism_records ADD COLUMN book_no VARCHAR(40) NULL AFTER registry_no",
        'page_no' => "ALTER TABLE baptism_records ADD COLUMN page_no VARCHAR(40) NULL AFTER book_no",
        'entry_no' => "ALTER TABLE baptism_records ADD COLUMN entry_no VARCHAR(40) NULL AFTER page_no"
    ];

    foreach ($optional_columns as $column => $sql) {
        if (!certColumnExists($conn, 'baptism_records', $column)) {
            $conn->query($sql);
        }
    }
}

// Certificate Record Meta Function - Documents this helper's role in the parish management workflow.
function certificateRecordMeta($cert_type) {
    if ($cert_type === 'baptism') {
        return ['table' => 'baptism_records', 'id' => 'baptism_id', 'prefix' => 'BAP', 'title' => 'CERTIFICATE OF BAPTISM'];
    }
    if ($cert_type === 'communion') {
        return ['table' => 'first_communion_records', 'id' => 'communion_id', 'prefix' => 'COM', 'title' => 'FIRST HOLY COMMUNION CERTIFICATE'];
    }
    return ['table' => 'confirmation_records', 'id' => 'confirmation_id', 'prefix' => 'CON', 'title' => 'CONFIRMATION CERTIFICATE'];
}

// Generate Certificate Number Function - Documents this helper's role in the parish management workflow.
function generateCertificateNumber($conn, $prefix) {
    $year = date('Y');
    $like = $conn->real_escape_string($prefix . '-' . $year . '-%');
    $result = $conn->query("SELECT certificate_number FROM certificate_issuances WHERE certificate_number LIKE '$like' ORDER BY certificate_id DESC LIMIT 1");
    $next = 1;
    if ($result && $row = $result->fetch_assoc()) {
        $parts = explode('-', $row['certificate_number']);
        $next = intval(end($parts)) + 1;
    }
    return $prefix . '-' . $year . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

// Get Or Create Certificate Issue Function - Documents this helper's role in the parish management workflow.
function getOrCreateCertificateIssue($conn, $cert_type, $data) {
    $meta = certificateRecordMeta($cert_type);
    $record_id = intval($data[$meta['id']] ?? 0);
    if ($record_id <= 0) {
        throw new Exception('Unable to identify the sacramental record for certificate issuance.');
    }

    $stmt = $conn->prepare("SELECT * FROM certificate_issuances WHERE certificate_type = ? AND record_table = ? AND record_id = ? AND status = 'valid' ORDER BY certificate_id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ssi', $cert_type, $meta['table'], $record_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            return $existing;
        }
    }

    $certificate_number = generateCertificateNumber($conn, $meta['prefix']);
    $verification_code = strtoupper($meta['prefix'] . '-' . bin2hex(random_bytes(4)) . '-' . substr(hash('sha256', $certificate_number . microtime(true)), 0, 8));
    $issued_by = intval($_SESSION['user_id'] ?? 0);
    $issued_to = $data['fullname'] ?? '';
    $stmt = $conn->prepare("INSERT INTO certificate_issuances (certificate_type, record_table, record_id, certificate_number, verification_code, issued_by, issued_to) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Unable to prepare certificate issuance record.');
    }
    $stmt->bind_param('ssissis', $cert_type, $meta['table'], $record_id, $certificate_number, $verification_code, $issued_by, $issued_to);
    $stmt->execute();
    $certificate_id = $stmt->insert_id;
    $stmt->close();

    createAuditLog($conn, $issued_by, 'ISSUE_CERTIFICATE', $meta['table'], $record_id, null, ['certificate_number' => $certificate_number]);

    return [
        'certificate_id' => $certificate_id,
        'certificate_type' => $cert_type,
        'record_table' => $meta['table'],
        'record_id' => $record_id,
        'certificate_number' => $certificate_number,
        'verification_code' => $verification_code,
        'issued_by' => $issued_by,
        'issued_to' => $issued_to,
        'status' => 'valid',
        'issued_at' => date('Y-m-d H:i:s')
    ];
}

// Display Date Function - Documents this helper's role in the parish management workflow.
function displayDate($value, $format = 'F d, Y') {
    if (empty($value) || $value === '0000-00-00') {
        return 'N/A';
    }
    $time = strtotime($value);
    return $time ? date($format, $time) : 'N/A';
}

// Split Parents Function - Documents this helper's role in the parish management workflow.
function splitParents($parents) {
    $result = ['father' => 'N/A', 'mother' => 'N/A'];
    $parents = trim((string) $parents);
    if ($parents === '') {
        return $result;
    }

    if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $parents, $matches)) {
        $result['father'] = trim($matches[1]);
        $result['mother'] = trim($matches[2]);
        return $result;
    }

    $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $parents);
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if (count($parts) >= 2) {
        $result['father'] = $parts[0];
        $result['mother'] = $parts[1];
    } else {
        $result['father'] = $parents;
    }
    return $result;
}

// Site Base URL Function - Documents this helper's role in the parish management workflow.
function siteBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . BASE_URL;
}

ensureCertificateSchema($conn);
$issue = getOrCreateCertificateIssue($conn, $cert_type, $data);
$parents = splitParents($data['parents'] ?? '');
$meta = certificateRecordMeta($cert_type);
$verification_url = siteBaseUrl() . 'verify-certificate.php?code=' . urlencode($issue['verification_code']);
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=132x132&margin=8&data=' . urlencode($verification_url);
$page_title = ucfirst($cert_type) . ' Certificate';
$parish_logo = '../church image.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ink: #151515; --muted: #4c4c4c; --line: #111; --gold: #b89945; }
        body { background: #eef1f5; color: var(--ink); }
        .cert-toolbar { max-width: 900px; margin: 18px auto; display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        .cert-toolbar h1 { font-size: 1.2rem; margin: 0; font-weight: 800; }
        .certificate-page { width: 210mm; min-height: 297mm; margin: 0 auto 24px; background: #fff; padding: 14mm; box-shadow: 0 18px 42px rgba(15, 23, 42, .18); }
        .certificate-sheet { min-height: 269mm; border: 2.5px solid var(--line); padding: 7mm 9mm 8mm; position: relative; overflow: hidden; font-family: "Times New Roman", Georgia, serif; }
        .certificate-sheet::before { content: ""; position: absolute; inset: 4mm; border: 1.5px solid var(--line); pointer-events: none; }
        .watermark-cross { position: absolute; inset: 35mm 35mm 42mm; opacity: .11; display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .watermark-cross::before { content: ""; width: 36mm; height: 118mm; background: var(--gold); position: absolute; border-radius: 2px; }
        .watermark-cross::after { content: ""; width: 96mm; height: 28mm; background: var(--gold); position: absolute; border-radius: 2px; }
        .watermark-text { position: absolute; top: 132mm; left: -20mm; right: -20mm; text-align: center; transform: rotate(-29deg); font-size: 23px; font-weight: 900; letter-spacing: 5px; color: rgba(0,0,0,.065); pointer-events: none; }
        .cert-content { position: relative; z-index: 1; }
        .cert-header { display: grid; grid-template-columns: 30mm 1fr 30mm; align-items: start; gap: 8mm; text-align: center; margin-bottom: 8mm; }
        .seal { width: 24mm; height: 24mm; border: 1.5px solid #111; border-radius: 50%; object-fit: cover; padding: 1.5mm; background: #fff; }
        .seal-emblem { margin: 0 auto; display: flex; align-items: center; justify-content: center; flex-direction: column; font-size: 6px; line-height: 1.05; font-weight: 900; text-align: center; }
        .seal-emblem i { font-size: 15px; margin-bottom: 1mm; color: #6f1d1b; }
        .diocese { font-size: 18px; font-weight: 900; letter-spacing: .5px; line-height: 1.1; }
        .parish { font-size: 13px; font-weight: 800; margin-top: 2mm; letter-spacing: .2px; }
        .location { font-size: 12px; font-weight: 700; margin-top: 2mm; }
        .cert-title { text-align: center; font-weight: 900; font-size: 24px; letter-spacing: 1px; text-decoration: underline; margin: 6mm 0 4mm; }
        .recipient { text-align: center; font-size: 19px; font-weight: 900; letter-spacing: .6px; text-transform: uppercase; margin-bottom: 5mm; }
        .statement { max-width: 156mm; margin: 0 auto 5mm; text-align: center; font-size: 13.5px; line-height: 1.45; }
        .details { display: grid; grid-template-columns: 35mm 1fr; column-gap: 5mm; row-gap: 1.6mm; max-width: 155mm; margin: 0 auto; font-size: 12.5px; }
        .details .label { font-weight: 700; color: #333; text-align: right; }
        .details .value { border-bottom: 1px dotted #999; min-height: 18px; font-weight: 700; }
        .church-line { text-align: center; margin: 6mm auto 2mm; font-weight: 800; font-size: 13px; max-width: 150mm; }
        .roman { display: block; font-size: 18px; font-weight: 950; letter-spacing: 1px; margin-top: 1mm; }
        .minister { text-align: center; margin: 2mm 0 5mm; font-size: 12px; }
        .minister strong { display: block; font-size: 15px; font-style: italic; text-decoration: underline; }
        .lower-grid { display: grid; grid-template-columns: 1.1fr .78fr; gap: 8mm; margin-top: 5mm; }
        .sponsors, .remarks, .auth-box { font-size: 11.5px; }
        .sponsors strong, .remarks strong, .auth-box strong { font-size: 12px; }
        .sponsor-lines { white-space: pre-line; border-bottom: 1px dotted #bbb; min-height: 28mm; padding-top: 1mm; }
        .registry-box { border: 1px solid #222; padding: 3mm; margin-top: 4mm; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2mm; font-size: 11px; }
        .issued { text-align: right; font-size: 11.5px; margin-top: 4mm; }
        .qr-row { display: flex; align-items: center; justify-content: flex-end; gap: 3mm; margin-top: 3mm; }
        .qr-row img { width: 26mm; height: 26mm; border: 1px solid #222; padding: 1mm; background: #fff; }
        .seal-area { width: 32mm; height: 24mm; border: 1px dashed #777; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 9px; color: #555; margin-left: auto; }
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14mm; margin-top: 10mm; align-items: end; }
        .signature { text-align: center; font-size: 12px; }
        .signature-line { border-top: 1.5px solid #111; padding-top: 1.5mm; font-weight: 900; min-height: 12mm; }
        .signature span { display: block; font-size: 10.5px; color: #333; font-weight: 500; margin-top: .5mm; }
        .certificate-number { position: absolute; top: 6mm; right: 9mm; font-family: Arial, sans-serif; font-size: 9.5px; font-weight: 800; }
        .verification-code { position: absolute; bottom: 4mm; left: 9mm; right: 9mm; font-family: Arial, sans-serif; font-size: 9px; display: flex; justify-content: space-between; color: #333; }
        .simple-preview { max-width: 900px; margin: 20px auto; background: #fff; padding: 40px; border: 2px solid #111; font-family: Georgia, serif; text-align: center; }
        .confirmation-page { width: 148mm; min-height: 210mm; margin: 0 auto 24px; background: #fff; padding: 7mm; box-shadow: 0 18px 42px rgba(15, 23, 42, .18); }
        .confirmation-sheet { min-height: 196mm; border: 1.8px solid #1f2933; padding: 5mm 6mm 5mm; position: relative; overflow: hidden; font-family: "Times New Roman", Georgia, serif; }
        .confirmation-sheet::before { content: ""; position: absolute; inset: 2.8mm; border: 1px solid #1f2933; pointer-events: none; }
        .confirmation-sheet::after { content: ""; position: absolute; left: 50%; top: 53%; width: 72mm; height: 72mm; transform: translate(-50%, -50%); background: url('<?php echo e($parish_logo); ?>') center / contain no-repeat; opacity: .11; pointer-events: none; }
        .confirmation-content { position: relative; z-index: 1; min-height: 184mm; display: flex; flex-direction: column; }
        .confirmation-header { display: grid; grid-template-columns: 22mm 1fr 22mm; gap: 2.5mm; align-items: start; text-align: center; margin-top: 2mm; }
        .confirmation-seal { width: 18mm; height: 18mm; border: 1px solid #1a1a1a; border-radius: 50%; object-fit: cover; background: #fff; padding: 1mm; }
        .confirmation-seal.seal-emblem { width: 18mm; height: 18mm; font-size: 4.4px; line-height: 1.05; color: #1a1a1a; }
        .confirmation-seal.seal-emblem i { font-size: 10px; color: #7a1d1d; margin-bottom: .5mm; }
        .confirmation-diocese { font-size: 13.5px; font-weight: 900; letter-spacing: .2px; line-height: 1.05; }
        .confirmation-parish { font-size: 10.5px; font-weight: 900; margin-top: 1.2mm; }
        .confirmation-location { font-size: 9.5px; font-weight: 700; margin-top: 1.6mm; }
        .confirmation-name { margin-top: 21mm; text-align: center; font-size: 15px; font-weight: 900; letter-spacing: .3px; text-decoration: underline; text-transform: uppercase; }
        .confirmation-facts { width: 76mm; margin: 8mm auto 0; font-size: 10px; line-height: 1.22; }
        .confirmation-facts .rowline { display: grid; grid-template-columns: 23mm 1fr; }
        .confirmation-facts strong { font-weight: 900; }
        .confirmation-rite { margin: 5.5mm auto 0; text-align: center; font-size: 12.5px; line-height: 1.18; }
        .confirmation-rite strong { display: block; font-size: 14.5px; font-weight: 950; letter-spacing: .6px; }
        .confirmation-event { margin-top: 2mm; text-align: center; font-size: 11px; line-height: 1.18; }
        .confirmation-event .minister-name { font-size: 12px; font-weight: 900; color: #14385e; }
        .confirmation-note { width: 89mm; margin: 1.5mm auto 0; text-align: left; font-size: 9.5px; line-height: 1.1; color: #454545; }
        .confirmation-registry { width: 78mm; margin: 1.5mm auto 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 1mm; font-size: 8.4px; color: #222; }
        .confirmation-purpose { width: 89mm; margin: 1mm auto 0; font-size: 9.5px; font-weight: 900; }
        .confirmation-issue { width: 46mm; margin: 13mm 17mm 0 auto; font-size: 8.5px; line-height: 1.25; }
        .confirmation-issue .issuer { margin-top: 2mm; text-align: center; }
        .confirmation-issue .issuer strong { display: block; border-bottom: 1px solid #333; font-size: 8.8px; }
        .confirmation-signature { margin: auto auto 3mm; width: 83mm; text-align: center; font-size: 9px; }
        .confirmation-signature strong { display: block; border-bottom: 1px solid #1f2933; padding-bottom: .7mm; font-size: 11px; letter-spacing: .2px; }
        .confirmation-signature span { display: block; margin-top: .7mm; font-size: 8px; }
        .confirmation-left-line, .confirmation-right-line { position: absolute; top: 60mm; bottom: 19mm; width: 1px; background: #1f2933; opacity: .75; }
        .confirmation-left-line { left: 5mm; }
        .confirmation-right-line { right: 5mm; }
        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { background: #fff; margin: 0; }
            .cert-toolbar { display: none; }
            .certificate-page { width: 210mm; min-height: 297mm; margin: 0; padding: 10mm; box-shadow: none; page-break-after: avoid; }
            .certificate-sheet { min-height: 277mm; }
            .confirmation-page { width: 148mm; min-height: 210mm; margin: 0 auto; padding: 7mm; box-shadow: none; page-break-after: avoid; }
            .confirmation-sheet { min-height: 196mm; }
        }
        @media (max-width: 900px) {
            .certificate-page { transform: scale(.55); transform-origin: top center; margin-bottom: -125mm; }
            .confirmation-page { transform: scale(.82); transform-origin: top center; margin-bottom: -35mm; }
            .cert-toolbar { padding: 0 14px; align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="cert-toolbar">
        <h1><i class="fas fa-certificate"></i> Certificate Preview</h1>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Certificate</button>
            <button class="btn btn-success" onclick="window.print()"><i class="fas fa-file-pdf"></i> Download PDF</button>
            <a class="btn btn-outline-dark" href="<?php echo e($verification_url); ?>" target="_blank"><i class="fas fa-shield-check"></i> Verify Certificate</a>
            <a href="certificate-generator.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <?php if ($cert_type === 'baptism'): ?>
        <main class="certificate-page">
            <section class="certificate-sheet">
                <div class="watermark-cross" aria-hidden="true"></div>
                <div class="watermark-text">OFFICIAL PARISH DOCUMENT</div>
                <div class="certificate-number"><?php echo e($issue['certificate_number']); ?></div>
                <div class="cert-content">
                    <header class="cert-header">
                        <div>
                            <div class="seal seal-emblem" aria-label="Archdiocese seal">
                                <i class="fas fa-cross"></i>
                                ARCHDIOCESE<br>OF<br>COTABATO
                            </div>
                        </div>
                        <div>
                            <div class="diocese">ARCHDIOCESE OF COTABATO</div>
                            <div class="parish">SAN LORENZO RUIZ MISSION STATION</div>
                            <div class="location">ALFONSAN, COTABATO</div>
                        </div>
                        <div><img class="seal" src="<?php echo e($parish_logo); ?>" alt="Parish seal"></div>
                    </header>

                    <div class="cert-title">CERTIFICATE OF BAPTISM</div>
                    <div class="recipient"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>

                    <p class="statement">
                        This is to certify that according to the records of this parish, the above-named person was solemnly baptized according to the rites of the
                        <strong>ROMAN CATHOLIC CHURCH</strong>.
                    </p>

                    <div class="details">
                        <div class="label">Full Name:</div><div class="value"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>
                        <div class="label">Date of Birth:</div><div class="value"><?php echo e(displayDate($data['birth_date'] ?? '')); ?></div>
                        <div class="label">Place of Birth:</div><div class="value"><?php echo e($data['birth_place'] ?? 'N/A'); ?></div>
                        <div class="label">Date of Baptism:</div><div class="value"><?php echo e(displayDate($data['baptism_date'] ?? '')); ?></div>
                        <div class="label">Registry No.:</div><div class="value"><?php echo e($data['registry_no'] ?? ($data['baptism_id'] ?? 'N/A')); ?></div>
                        <div class="label">Father:</div><div class="value"><?php echo e($parents['father']); ?></div>
                        <div class="label">Mother:</div><div class="value"><?php echo e($parents['mother']); ?></div>
                        <div class="label">Residence:</div><div class="value"><?php echo e($data['parent_address'] ?? $data['parish_address'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="church-line">
                        Was Solemnly Baptized according to the Rites of the
                        <span class="roman">ROMAN CATHOLIC CHURCH</span>
                    </div>
                    <div class="minister">
                        By:
                        <strong><?php echo e($data['priest'] ?? 'N/A'); ?></strong>
                        Minister / Celebrant
                    </div>

                    <div class="lower-grid">
                        <div>
                            <div class="sponsors">
                                <strong>Sponsors / Godparents:</strong>
                                <div class="sponsor-lines"><?php echo e($data['godparents'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="registry-box">
                                <div><strong>Book No.</strong><br><?php echo e($data['book_no'] ?? 'N/A'); ?></div>
                                <div><strong>Page No.</strong><br><?php echo e($data['page_no'] ?? 'N/A'); ?></div>
                                <div><strong>Entry No.</strong><br><?php echo e($data['entry_no'] ?? ($data['registry_no'] ?? $data['baptism_id'] ?? 'N/A')); ?></div>
                                <div><strong>Baptism Date</strong><br><?php echo e(displayDate($data['baptism_date'] ?? '', 'm/d/Y')); ?></div>
                                <div><strong>Reference</strong><br><?php echo e($issue['certificate_number']); ?></div>
                                <div><strong>Status</strong><br><?php echo e(ucfirst($issue['status'])); ?></div>
                            </div>
                            <div class="remarks mt-2">
                                <strong>Remarks:</strong> <?php echo e($data['remarks'] ?? 'Issued for parish record purposes.'); ?>
                            </div>
                        </div>
                        <div class="auth-box">
                            <div class="issued">
                                <strong>Date Issued:</strong><br><?php echo e(displayDate($issue['issued_at'] ?? date('Y-m-d'))); ?><br>
                                <strong>Verification Ref.:</strong><br><?php echo e($issue['verification_code']); ?>
                            </div>
                            <div class="qr-row">
                                <div class="seal-area">Official<br>Dry Seal<br>Area</div>
                                <img src="<?php echo e($qr_url); ?>" alt="Certificate verification QR code">
                            </div>
                        </div>
                    </div>

                    <div class="signature-grid">
                        <div class="signature">
                            <div class="signature-line">REV. FR. ROGELIO C. CAALIM, OMJ</div>
                            <span>Parish Priest</span>
                        </div>
                        <div class="signature">
                            <div class="signature-line">AUTHORIZED PARISH STAFF</div>
                            <span>Parish Secretary</span>
                        </div>
                    </div>
                </div>
                <div class="verification-code">
                    <span>Verify: <?php echo e($verification_url); ?></span>
                    <span>Unauthorized alteration invalidates this certificate.</span>
                </div>
            </section>
        </main>
    <?php elseif ($cert_type === 'confirmation'): ?>
        <main class="confirmation-page">
            <section class="confirmation-sheet">
                <div class="confirmation-left-line" aria-hidden="true"></div>
                <div class="confirmation-right-line" aria-hidden="true"></div>
                <div class="confirmation-content">
                    <header class="confirmation-header">
                        <div>
                            <div class="confirmation-seal seal-emblem" aria-label="Archdiocese seal">
                                <i class="fas fa-cross"></i>
                                ARCHDIOCESE<br>OF<br>COTABATO
                            </div>
                        </div>
                        <div>
                            <div class="confirmation-diocese">ARCHDIOCESE OF COTABATO</div>
                            <div class="confirmation-parish">SAN LORENZO RUIZ MISSION STATION</div>
                            <div class="confirmation-location">ALEOSAN, COTABATO</div>
                        </div>
                        <div><img class="confirmation-seal" src="<?php echo e($parish_logo); ?>" alt="Parish seal"></div>
                    </header>

                    <div class="confirmation-name"><?php echo e($data['fullname'] ?? 'N/A'); ?></div>

                    <div class="confirmation-facts">
                        <div class="rowline"><strong>Child of:</strong><span><?php echo e($data['parents'] ?? 'N/A'); ?></span></div>
                        <div class="rowline"><strong>and</strong><span><?php echo e($data['origin_parish'] ?? $data['origin_province'] ?? 'N/A'); ?></span></div>
                        <div class="rowline"><strong>Born In:</strong><span><?php echo e($data['baptismal_place'] ?? $data['origin_province'] ?? 'N/A'); ?></span></div>
                        <div class="rowline"><strong>Born On</strong><span><?php echo e(displayDate($data['birth_date'] ?? '')); ?></span></div>
                    </div>

                    <div class="confirmation-rite">
                        Was Solemnly Confirmed according to the rite of the
                        <strong>ROMAN CATHOLIC CHURCH</strong>
                    </div>

                    <div class="confirmation-event">
                        On <?php echo e(displayDate($data['confirmation_date'] ?? '')); ?><br>
                        by: <span class="minister-name"><?php echo e($data['bishop_priest'] ?? 'N/A'); ?></span>
                    </div>

                    <div class="confirmation-note">
                        The sponsor being: <?php echo e($data['sponsor'] ?? 'N/A'); ?><br>
                        whose name appears from the Book of Confirmation.
                    </div>

                    <div class="confirmation-registry">
                        <div>Book No: <?php echo e($data['book_no'] ?? '1'); ?></div>
                        <div>PageNo: <?php echo e($data['page_no'] ?? '1'); ?></div>
                        <div>LineNo: <?php echo e($data['registry_no'] ?? ($data['confirmation_id'] ?? '1')); ?></div>
                        <div>Ref: <?php echo e($issue['certificate_number']); ?></div>
                    </div>

                    <div class="confirmation-purpose">
                        This is issued upon the request of the aforementioned person for<br>
                        For marriage purpose:
                    </div>

                    <div class="confirmation-issue">
                        Issued on: <?php echo e(displayDate($issue['issued_at'] ?? date('Y-m-d'))); ?><br>
                        Issued by:
                        <div class="issuer">
                            <strong><?php echo e($_SESSION['fullname'] ?? 'Authorized Staff'); ?></strong>
                            Secretary
                        </div>
                    </div>

                    <div class="confirmation-signature">
                        <strong>REV. FR. ROGELIO C. CAALIM, OMJ</strong>
                        <span>Parish Priest</span>
                    </div>
                </div>
            </section>
        </main>
    <?php else: ?>
        <div class="simple-preview">
            <h1><?php echo e($meta['title']); ?></h1>
            <h2><?php echo e($data['fullname'] ?? 'N/A'); ?></h2>
            <p>This sacramental certificate is generated from parish records.</p>
            <p><strong>Certificate No:</strong> <?php echo e($issue['certificate_number']); ?></p>
            <p><strong>Verification Code:</strong> <?php echo e($issue['verification_code']); ?></p>
        </div>
    <?php endif; ?>
</body>
</html>
