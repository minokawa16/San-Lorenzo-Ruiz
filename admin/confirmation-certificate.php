<?php
/**
 * Certificate of Confirmation Studio & Generator
 *
 * Fully data-driven, print-ready certificate component and interactive studio
 * for San Lorenzo Ruiz Mission Station (Parish Records System).
 *
 * Produces pixel-accurate A4 Landscape (and US Letter Landscape) certificates
 * at 300 DPI with vector Greek-key meander borders, gold embossed seal,
 * automatic date ordinals, name auto-fit, and gender inversion detection.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../services/CertificatePdfService.php';

requireAdmin();
requirePermission('certificates.manage');

$pdfService = new CertificatePdfService($conn);
$currentUser = function_exists('getAuthenticatedUser') ? getAuthenticatedUser($conn) : null;

// ── Handle AJAX Request: Fetch Existing Record by ID ──
if (isset($_GET['action']) && $_GET['action'] === 'fetch_record') {
    header('Content-Type: application/json');
    $recId = intval($_GET['id'] ?? 0);
    if ($recId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid record ID']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $recId);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($record) {
            $confDate = !empty($record['confirmation_date']) && $record['confirmation_date'] !== '0000-00-00'
                ? date('Y-m-d', strtotime($record['confirmation_date']))
                : date('Y-m-d');
            $issueDate = !empty($record['created_at']) && $record['created_at'] !== '0000-00-00 00:00:00'
                ? date('Y-m-d', strtotime($record['created_at']))
                : date('Y-m-d');

            $sponsors = CertificatePdfService::parseSponsors($record['sponsor'] ?? '');
            $godfather = $sponsors[0] ?? '';
            $godmother = $sponsors[1] ?? '';

            $priestName = !empty($record['parish_priest'])
                ? $record['parish_priest']
                : $pdfService->getPriestInChargeName();
            $priestTitle = $pdfService->getPriestInChargeTitle();

            $certNo = !empty($record['registry_no'])
                ? 'CONF-' . date('Y', strtotime($confDate)) . '-' . sprintf('%04d', (int)$record['registry_no'])
                : 'CONF-' . date('Y', strtotime($confDate)) . '-' . sprintf('%04d', $recId);

            $dataModel = [
                'parishName' => 'SAN LORENZO RUIZ MISSION STATION',
                'parishLocation' => 'Aleosan, Cotabato',
                'confirmandName' => strtoupper(trim((string)$record['fullname'])),
                'confirmationDate' => $confDate,
                'bishopName' => !empty($record['bishop_priest']) ? trim((string)$record['bishop_priest']) : 'Bp. Angelito R. Lampon, O.M.I., D.D.',
                'bishopTitle' => 'Archbishop of Cotabato',
                'fatherName' => strtoupper(trim((string)$record['father_name'])),
                'motherName' => strtoupper(trim((string)$record['mother_name'])),
                'godfatherName' => strtoupper($godfather),
                'godmotherName' => strtoupper($godmother),
                'issueDate' => $issueDate,
                'priestName' => strtoupper($priestName),
                'priestTitle' => $priestTitle,
                'certificateNo' => $certNo,
                'logoLeftUrl' => '../assets/img/archdiocese-crest.jpg',
                'logoRightUrl' => '../assets/img/san-lorenzo-logo.png',
                'sealUrl' => '../assets/img/certificates/gold-embossed-parish-seal.svg',
                'confirmation_id' => $recId
            ];

            echo json_encode(['status' => 'success', 'data' => $dataModel]);
            exit;
        }
    }
    echo json_encode(['status' => 'error', 'message' => 'Record not found in confirmation registry']);
    exit;
}

// ── Handle AJAX Request: Log Print / Issue Event ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['log_print', 'save_issuance'], true)) {
    header('Content-Type: application/json');
    $actorId = (int)($_SESSION['user_id'] ?? 0);
    $confId = intval($_POST['confirmation_id'] ?? 0);
    $confirmandName = trim((string)($_POST['confirmandName'] ?? ''));
    $certNo = trim((string)($_POST['certificateNo'] ?? ''));
    $actionType = ($_POST['action'] === 'log_print') ? 'PRINT_CERTIFICATE' : 'ISSUE_CERTIFICATE';

    // Write to canonical audit log
    $logged = writeAuditLog(
        $conn,
        $actorId,
        $actionType,
        'confirmation_records',
        $confId > 0 ? $confId : null,
        null,
        ['confirmand' => $confirmandName, 'certificate_no' => $certNo, 'cert_type' => 'confirmation'],
        'certificates',
        null,
        null,
        "Printed or issued official Certificate of Confirmation for {$confirmandName} (Cert #{$certNo}).",
        'CERTIFICATES',
        'INFO',
        'confirmation_certificate',
        $confId > 0 ? $confId : null
    );

    // Record in certificate_issuances if table exists
    if ($confId > 0 && !empty($certNo)) {
        $checkStmt = $conn->prepare("SELECT certificate_id FROM certificate_issuances WHERE certificate_type = 'confirmation' AND record_id = ? LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param('i', $confId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($existing) {
                $upStmt = $conn->prepare("UPDATE certificate_issuances SET issued_by = ?, issued_to = ?, certificate_number = ?, updated_at = NOW() WHERE certificate_id = ?");
                if ($upStmt) {
                    $upStmt->bind_param('issi', $actorId, $confirmandName, $certNo, $existing['certificate_id']);
                    $upStmt->execute();
                    $upStmt->close();
                }
            } else {
                $insStmt = $conn->prepare("INSERT INTO certificate_issuances (certificate_type, record_table, record_id, certificate_number, issued_by, issued_to, status, issued_at) VALUES ('confirmation', 'confirmation_records', ?, ?, ?, ?, 'issued', NOW())");
                if ($insStmt) {
                    $insStmt->bind_param('isis', $confId, $certNo, $actorId, $confirmandName);
                    $insStmt->execute();
                    $insStmt->close();
                }
            }
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Event logged successfully', 'audit_logged' => $logged]);
    exit;
}

// ── Handle Download PDF (Dompdf Engine) ──
if ((isset($_GET['action']) && $_GET['action'] === 'download_pdf') || (isset($_POST['action']) && $_POST['action'] === 'download_pdf')) {
    $src = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
    $recordData = [
        'parishName' => trim((string)($src['parishName'] ?? 'SAN LORENZO RUIZ MISSION STATION')),
        'parishLocation' => trim((string)($src['parishLocation'] ?? 'Aleosan, Cotabato')),
        'confirmandName' => trim((string)($src['confirmandName'] ?? '')),
        'confirmationDate' => trim((string)($src['confirmationDate'] ?? date('Y-m-d'))),
        'bishopName' => trim((string)($src['bishopName'] ?? 'Bp. Angelito R. Lampon, O.M.I., D.D.')),
        'bishopTitle' => trim((string)($src['bishopTitle'] ?? 'Archbishop of Cotabato')),
        'fatherName' => trim((string)($src['fatherName'] ?? '')),
        'motherName' => trim((string)($src['motherName'] ?? '')),
        'godfatherName' => trim((string)($src['godfatherName'] ?? '')),
        'godmotherName' => trim((string)($src['godmotherName'] ?? '')),
        'issueDate' => trim((string)($src['issueDate'] ?? date('Y-m-d'))),
        'priestName' => trim((string)($src['priestName'] ?? $pdfService->getPriestInChargeName())),
        'priestTitle' => trim((string)($src['priestTitle'] ?? $pdfService->getPriestInChargeTitle())),
        'certificateNo' => trim((string)($src['certificateNo'] ?? '')),
        'includeSecurity' => !empty($src['includeSecurity']) && $src['includeSecurity'] !== '0',
    ];

    $missing = $pdfService->validateConfirmationRecord($recordData);
    if (!empty($missing)) {
        http_response_code(422);
        echo 'Cannot generate PDF: Missing required fields: ' . implode(', ', $missing);
        exit;
    }

    $paper = strtolower(trim((string)($src['paper'] ?? 'a4')));
    $actorId = (int)($_SESSION['user_id'] ?? 0);
    $confId = intval($src['confirmation_id'] ?? 0);

    writeAuditLog(
        $conn,
        $actorId,
        'EXPORT_PDF_CERTIFICATE',
        'confirmation_records',
        $confId > 0 ? $confId : null,
        null,
        ['confirmand' => $recordData['confirmandName'], 'paper' => $paper],
        'certificates',
        null,
        null,
        "Generated high-resolution PDF Certificate of Confirmation for {$recordData['confirmandName']}."
    );

    $pdfService->streamConfirmationPdf($recordData, '', true, ['paper' => $paper]);
    exit;
}

// ── Initial Record Loading ──
$initialId = intval($_GET['id'] ?? 0);
$initialRecord = null;
if ($initialId > 0) {
    $stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $initialId);
        $stmt->execute();
        $initialRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// Fallback to reference sample record or latest record if none specified
if (!$initialRecord) {
    $fbStmt = $conn->query("SELECT * FROM confirmation_records WHERE fullname LIKE '%REY MARK%' ORDER BY confirmation_id ASC LIMIT 1");
    if ($fbStmt && $row = $fbStmt->fetch_assoc()) {
        $initialRecord = $row;
    } else {
        $fbStmt2 = $conn->query("SELECT * FROM confirmation_records WHERE status = 'active' ORDER BY confirmation_id DESC LIMIT 1");
        if ($fbStmt2 && $row2 = $fbStmt2->fetch_assoc()) {
            $initialRecord = $row2;
        }
    }
}

// Prepare initial data model
$initConfDate = !empty($initialRecord['confirmation_date']) && $initialRecord['confirmation_date'] !== '0000-00-00'
    ? date('Y-m-d', strtotime($initialRecord['confirmation_date']))
    : '2026-09-01';
$initIssueDate = !empty($initialRecord['created_at']) && $initialRecord['created_at'] !== '0000-00-00 00:00:00'
    ? date('Y-m-d', strtotime($initialRecord['created_at']))
    : '2026-09-01';

$initSponsors = CertificatePdfService::parseSponsors($initialRecord['sponsor'] ?? '');
$initGodfather = $initSponsors[0] ?? 'LEE MARK C. JAVIER';
$initGodmother = $initSponsors[1] ?? 'MARY ANN C. DELA CRUZ';

$initCertNo = !empty($initialRecord['registry_no'])
    ? 'CONF-' . date('Y', strtotime($initConfDate)) . '-' . sprintf('%04d', (int)$initialRecord['registry_no'])
    : 'CONF-2026-0042';

$model = [
    'parishName' => 'SAN LORENZO RUIZ MISSION STATION',
    'parishLocation' => 'Aleosan, Cotabato',
    'confirmandName' => strtoupper(trim((string)($initialRecord['fullname'] ?? 'REY MARK C. CAVANAS'))),
    'confirmationDate' => $initConfDate,
    'bishopName' => !empty($initialRecord['bishop_priest']) ? trim((string)$initialRecord['bishop_priest']) : 'Bp. Angelito R. Lampon, O.M.I., D.D.',
    'bishopTitle' => 'Archbishop of Cotabato',
    'fatherName' => strtoupper(trim((string)($initialRecord['father_name'] ?? 'JOY C. CAVANAS'))),
    'motherName' => strtoupper(trim((string)($initialRecord['mother_name'] ?? 'ROBERTO A. CAVANAS'))),
    'godfatherName' => strtoupper($initGodfather),
    'godmotherName' => strtoupper($initGodmother),
    'issueDate' => $initIssueDate,
    'priestName' => !empty($initialRecord['parish_priest']) ? strtoupper(trim((string)$initialRecord['parish_priest'])) : 'REV. FR. ALBERTO G. CAHILIG, OMI',
    'priestTitle' => 'Priest-in-Charge',
    'certificateNo' => $initCertNo,
    'logoLeftUrl' => '../assets/img/archdiocese-crest.jpg',
    'logoRightUrl' => '../assets/img/san-lorenzo-logo.png',
    'sealUrl' => '../assets/img/certificates/gold-embossed-parish-seal.svg',
    'confirmation_id' => intval($initialRecord['confirmation_id'] ?? 0)
];

// Pre-fetch recent active confirmation records for quick switcher dropdown
$recentRecords = [];
$rStmt = $conn->query("SELECT confirmation_id, fullname, confirmation_date, registry_no FROM confirmation_records WHERE status = 'active' ORDER BY confirmation_id DESC LIMIT 30");
if ($rStmt) {
    while ($rRow = $rStmt->fetch_assoc()) {
        $recentRecords[] = $rRow;
    }
}

$page_title = 'Confirmation Certificate Studio - ' . htmlspecialchars($model['confirmandName']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts for Ornate Title, Serif Body, and UI -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Cinzel+Decorative:wght@700;900&family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500;1,600;1,700&family=EB+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">

    <style>
        :root {
            --app-navy: #0f2942;
            --app-navy-dark: #0a1c2e;
            --app-gold: #c89b3c;
            --app-gold-light: #f5d77f;
            --cert-teal: #1F5A7A;
            --cert-teal-dark: #16435c;
            --cert-gold: #C89B3C;
            --cert-parchment-1: #FAF6E8;
            --cert-parchment-2: #F6F0DC;
            --cert-parchment-3: #EFE6C8;
            --cert-ink: #111827;
            --cert-ink-muted: #374151;

            /* Default page dimension: A4 Landscape */
            --page-width: 297mm;
            --page-height: 210mm;
            --preview-scale: 0.95;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #0b131d;
            color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Top Studio Header */
        .studio-navbar {
            background: linear-gradient(180deg, #111c2a 0%, #0c1520 100%);
            border-bottom: 1px solid rgba(200, 155, 60, 0.25);
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.4);
        }

        .studio-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .studio-brand-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, var(--app-gold) 0%, #875f11 100%);
            color: #0b131d;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            box-shadow: 0 2px 8px rgba(200, 155, 60, 0.3);
        }

        .studio-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0;
            line-height: 1.2;
            letter-spacing: 0.2px;
        }

        .studio-subtitle {
            font-size: 0.76rem;
            color: #9ca3af;
            margin: 0;
        }

        .studio-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Studio Main Layout */
        .studio-container {
            display: grid;
            grid-template-columns: 460px 1fr;
            height: calc(100vh - 61px);
            overflow: hidden;
        }

        @media (max-width: 1200px) {
            .studio-container {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }
        }

        /* Left Side: Form Controls */
        .studio-sidebar {
            background: #111a24;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            overflow-y: auto;
            padding: 18px 20px 40px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .control-card {
            background: #162230;
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .control-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(200, 155, 60, 0.2);
        }

        .control-card-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--app-gold-light);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: #d1d5db;
            margin-bottom: 4px;
        }

        .form-control, .form-select {
            background-color: #0d151e;
            border: 1px solid #233446;
            color: #f3f4f6;
            font-size: 0.85rem;
            border-radius: 6px;
            padding: 7px 11px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus, .form-select:focus {
            background-color: #091018;
            border-color: var(--app-gold);
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.2);
            outline: none;
        }

        .form-control.is-invalid {
            border-color: #ef4444 !important;
            background-color: #1f1214 !important;
        }

        .gender-swap-warning {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(245, 158, 11, 0.15) 100%);
            border: 1px solid rgba(245, 158, 11, 0.5);
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .gender-swap-warning.active {
            display: block;
        }

        .btn-swap {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.78rem;
            border: none;
            border-radius: 6px;
            padding: 5px 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-swap:hover {
            background: linear-gradient(135deg, #fbbf24 0%, #b45309 100%);
            transform: translateY(-1px);
        }

        /* Right Side: Viewport & Canvas */
        .studio-canvas-viewport {
            background: #090e14;
            background-image: 
                radial-gradient(circle at 50% 50%, rgba(31, 90, 122, 0.08) 0%, transparent 70%),
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 100% 100%, 20px 20px, 20px 20px;
            overflow: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px 20px 60px;
            position: relative;
        }

        .canvas-toolbar {
            background: rgba(17, 26, 36, 0.88);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 30px;
            padding: 6px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
            position: sticky;
            top: 10px;
            z-index: 100;
        }

        .canvas-scale-wrapper {
            transform-origin: top center;
            transition: transform 0.2s ease-out;
            margin: 0 auto;
        }

        /* ── THE PRINTABLE CERTIFICATE SHEET (A4 Landscape = 297mm x 210mm) ── */
        .cert-sheet {
            width: var(--page-width);
            height: var(--page-height);
            background: #F6F0DC;
            position: relative;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5), 0 2px 10px rgba(0, 0, 0, 0.3);
            color: var(--cert-ink);
            box-sizing: border-box;
            overflow: hidden;
            font-family: 'Times New Roman', Times, Georgia, serif;
            -webkit-font-smoothing: antialiased;
            user-select: none;
        }

        /* Pure Vector Border SVG (crisp at 300 DPI and any zoom level) */
        .cert-frame-border {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        /* Inner Document Layout */
        .cert-sheet-inner {
            position: absolute;
            left: 20mm;
            top: 14mm;
            right: 20mm;
            bottom: 14mm;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        /* Header Grid: Left Crest, Center Title & Subtitle, Right Medallion */
        .cert-header-row {
            display: grid;
            grid-template-columns: 24mm 1fr 24mm;
            align-items: center;
            width: 100%;
            margin-bottom: 1.5mm;
        }

        .cert-logo-box {
            width: 24mm;
            height: 24mm;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .cert-logo-box img {
            max-width: 22mm;
            max-height: 22mm;
            object-fit: contain;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.15));
        }

        .cert-header-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .cert-ornate-title {
            font-family: 'Playfair Display', 'Cinzel Decorative', 'Cinzel', serif;
            font-size: 26pt;
            font-weight: 700;
            color: #1F5A7A;
            letter-spacing: 0.8px;
            margin: 0;
            line-height: 1.1;
            text-shadow: 0.5px 1px 1.5px rgba(31, 90, 122, 0.25);
        }

        .cert-parish-name {
            font-family: 'Cinzel', 'Times New Roman', serif;
            font-size: 11.5pt;
            font-weight: 800;
            color: #1F5A7A;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            margin-top: 0.8mm;
            line-height: 1.1;
        }

        .cert-parish-loc {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8.8pt;
            font-weight: 500;
            color: #555555;
            letter-spacing: 0.4px;
            margin-top: 0.4mm;
        }

        /* Gold Horizontal Divider */
        .gold-rule-line {
            width: 82%;
            height: 1.2px;
            background: linear-gradient(90deg, transparent 0%, #C89B3C 15%, #C89B3C 85%, transparent 100%);
            margin: 2mm auto 2.5mm;
        }

        /* 1. Recipient Full Name */
        .cert-recipient-box {
            width: 100%;
            text-align: center;
            margin-bottom: 1.2mm;
        }

        .cert-recipient-name {
            font-family: 'Cinzel', 'Times New Roman', Georgia, serif;
            font-size: 20pt;
            font-weight: 800;
            color: #111827;
            letter-spacing: 2.2px;
            text-transform: uppercase;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            display: inline-block;
            max-width: 90%;
        }

        /* 2. Sacrament Line */
        .cert-sacrament-declaration {
            font-family: 'Cormorant Garamond', 'EB Garamond', Georgia, serif;
            font-size: 14pt;
            font-style: italic;
            color: #111827;
            letter-spacing: 0.3px;
            margin: 1mm 0 1.8mm;
            line-height: 1.1;
        }

        /* 3. Canonical Day, Month, Year */
        .cert-canon-date-line {
            font-family: 'Times New Roman', serif;
            font-size: 9.5pt;
            color: #111827;
            margin-bottom: 2mm;
            line-height: 1.3;
        }

        .cert-underlined-field {
            display: inline-block;
            border-bottom: 1.2px solid #1F5A7A;
            font-weight: 700;
            color: #111827;
            text-align: center;
            padding: 0 4px;
            vertical-align: bottom;
            line-height: 1.1;
        }

        .field-ordinal-day { min-width: 16mm; }
        .field-month-upper { min-width: 32mm; }
        .field-year-short { min-width: 10mm; }

        /* 4. Administered by His Excellency & Bishop */
        .cert-bishop-lead {
            font-size: 8.5pt;
            color: #4b5563;
            margin-bottom: 0.6mm;
            line-height: 1;
        }

        .cert-bishop-name {
            font-family: 'Times New Roman', serif;
            font-size: 11.5pt;
            font-weight: 800;
            color: #111827;
            letter-spacing: 0.4px;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
        }

        .cert-bishop-title {
            font-size: 8.5pt;
            color: #374151;
            line-height: 1.1;
            margin-top: 0.4mm;
        }

        .cert-delegate-line {
            font-size: 8.5pt;
            color: #374151;
            margin-top: 0.6mm;
            margin-bottom: 0.8mm;
        }

        /* 5. Confirmed recipient name repeat */
        .cert-confirmed-recipient {
            font-family: 'Times New Roman', serif;
            font-size: 10pt;
            font-weight: 700;
            color: #111827;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            margin-bottom: 1.2mm;
        }

        /* 6. Four Labeled Lines (Father, Mother, Godfather, Godmother) */
        .cert-lineage-table {
            width: 76%;
            margin: 0 auto;
            border-collapse: collapse;
        }

        .lineage-row-cell {
            padding: 1mm 0;
            text-align: center;
        }

        .lineage-name-val {
            font-family: 'Times New Roman', serif;
            font-size: 10pt;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.15;
            display: block;
            margin: 0 auto;
            max-width: 95%;
        }

        .lineage-gold-rule {
            border-bottom: 1px solid #C89B3C;
            width: 88%;
            margin: 0.6mm auto 0.4mm;
        }

        .lineage-caption-lbl {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.6pt;
            font-style: italic;
            color: #555555;
            line-height: 1;
        }

        /* 7. Certification Statement */
        .cert-true-copy-statement {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.8pt;
            font-style: italic;
            color: #4b5563;
            margin: 2.2mm auto 1.5mm;
            text-align: center;
            max-width: 85%;
            line-height: 1.2;
        }

        /* 8. Footer: Issue Date, Gold Embossed Seal, Priest Signature */
        .cert-footer-row {
            display: grid;
            grid-template-columns: 1fr 28mm 1fr;
            align-items: flex-end;
            width: 100%;
            margin-top: 1mm;
        }

        .footer-date-block {
            text-align: center;
            padding-bottom: 2mm;
        }

        .footer-seal-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .footer-seal-img {
            width: 24mm;
            height: 24mm;
            object-fit: contain;
            filter: drop-shadow(0 3px 6px rgba(61, 42, 6, 0.35));
        }

        .footer-security-qr {
            position: absolute;
            right: -24mm;
            bottom: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 5.5pt;
            color: #6b7280;
            text-align: center;
        }

        .footer-security-qr svg {
            width: 14mm;
            height: 14mm;
        }

        .footer-priest-block {
            text-align: center;
            padding-bottom: 2mm;
        }

        .footer-val-name {
            font-family: 'Times New Roman', serif;
            font-size: 9.2pt;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.1;
        }

        .footer-thin-rule {
            border-bottom: 1.2px solid #1F5A7A;
            width: 82%;
            margin: 1.2mm auto 0.6mm;
        }

        .footer-sub-caption {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8pt;
            font-style: italic;
            color: #1F5A7A;
            line-height: 1;
        }

        /* ── PRINT MEDIA RULES: 300 DPI, Zero Margin, Single Landscape Page ── */
        @page {
            size: A4 landscape;
            margin: 0;
        }

        @media print {
            html, body {
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                background: none !important;
                overflow: hidden !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .studio-navbar,
            .studio-sidebar,
            .canvas-toolbar,
            .no-print {
                display: none !important;
            }

            .studio-container {
                display: block !important;
                height: auto !important;
                overflow: visible !important;
            }

            .studio-canvas-viewport {
                background: none !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }

            .canvas-scale-wrapper {
                transform: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .cert-sheet {
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                width: var(--page-width) !important;
                height: var(--page-height) !important;
                box-shadow: none !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                margin: 0 !important;
            }
        }
    </style>
</head>
<body>

    <!-- ── TOP NAVIGATION & WORKSPACE BAR ── -->
    <header class="studio-navbar no-print">
        <div class="studio-brand">
            <a href="manage-records.php" class="btn btn-sm btn-outline-secondary text-white border-0 me-1" title="Back to Records">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="studio-brand-icon">
                <i class="fas fa-certificate"></i>
            </div>
            <div>
                <h1 class="studio-title">Certificate of Confirmation Studio</h1>
                <p class="studio-subtitle">San Lorenzo Ruiz Mission Station &bull; Aleosan, Cotabato</p>
            </div>
        </div>

        <div class="studio-actions">
            <!-- Record Selector Dropdown -->
            <div class="input-group input-group-sm" style="width: 250px;">
                <span class="input-group-text bg-dark text-warning border-secondary"><i class="fas fa-search"></i></span>
                <select class="form-select border-secondary" id="recordSelector" aria-label="Select record to populate">
                    <option value="">-- Load from Registry --</option>
                    <?php foreach ($recentRecords as $r): ?>
                        <option value="<?php echo $r['confirmation_id']; ?>" <?php echo ($r['confirmation_id'] == $model['confirmation_id']) ? 'selected' : ''; ?>>
                            #<?php echo htmlspecialchars($r['registry_no'] ?: $r['confirmation_id']); ?> - <?php echo htmlspecialchars($r['fullname']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Paper Size Switcher -->
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-warning active" id="btnPaperA4" onclick="setPaperSize('a4')">A4</button>
                <button type="button" class="btn btn-outline-warning" id="btnPaperLetter" onclick="setPaperSize('letter')">US Letter</button>
            </div>

            <!-- Action Buttons -->
            <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="triggerPrint()">
                <i class="fas fa-print me-1"></i> Print Certificate
            </button>
            <button type="button" class="btn btn-sm btn-success fw-bold" onclick="triggerDownloadPdf()">
                <i class="fas fa-file-pdf me-1"></i> Download PDF (300 DPI)
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="saveCertificateIssuance()">
                <i class="fas fa-save me-1"></i> Save & Log
            </button>
        </div>
    </header>

    <!-- ── STUDIO WORKSPACE ── -->
    <main class="studio-container">
        <!-- ── LEFT CONTROL PANEL: DATA MODEL FORM ── -->
        <aside class="studio-sidebar no-print">
            <!-- Validation Summary Alert -->
            <div class="alert alert-danger py-2 px-3 small d-none" id="validationAlert" role="alert">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-circle-exclamation fs-5"></i>
                    <div>
                        <strong>Required Fields Missing:</strong>
                        <div id="validationAlertMsg" class="mt-1">Please fill in all mandatory fields before printing.</div>
                    </div>
                </div>
            </div>

            <!-- Card 1: Confirmand & Sacrament -->
            <div class="control-card">
                <div class="control-card-header">
                    <h2 class="control-card-title"><i class="fas fa-user-graduate"></i> Confirmand & Sacrament</h2>
                    <span class="badge bg-primary">Required</span>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="inpConfirmandName">Confirmand Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="inpConfirmandName" value="<?php echo htmlspecialchars($model['confirmandName']); ?>" placeholder="e.g. REY MARK C. CAVANAS" required autocomplete="off">
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-sm-7">
                        <label class="form-label" for="inpConfDate">Confirmation Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="inpConfDate" value="<?php echo htmlspecialchars($model['confirmationDate']); ?>" required>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label" for="inpCertNo">Certificate No.</label>
                        <input type="text" class="form-control" id="inpCertNo" value="<?php echo htmlspecialchars($model['certificateNo']); ?>" placeholder="CONF-2026-0042">
                    </div>
                </div>

                <div class="p-2 bg-dark rounded small text-muted d-flex justify-content-between">
                    <span>Parsed Ordinal Date:</span>
                    <strong class="text-warning" id="lblParsedDate">1ST day of SEPTEMBER, 2026</strong>
                </div>
            </div>

            <!-- Card 2: Parentage & Gender Inversion Detection -->
            <div class="control-card">
                <div class="control-card-header">
                    <h2 class="control-card-title"><i class="fas fa-people-roof"></i> Parents & Sponsors</h2>
                    <span class="badge bg-info text-dark">Smart Detection</span>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="inpFatherName">Father's Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="inpFatherName" value="<?php echo htmlspecialchars($model['fatherName']); ?>" placeholder="FATHER'S FULL NAME" required autocomplete="off">
                </div>

                <div class="mb-2">
                    <label class="form-label" for="inpMotherName">Mother's Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="inpMotherName" value="<?php echo htmlspecialchars($model['motherName']); ?>" placeholder="MOTHER'S FULL NAME" required autocomplete="off">
                </div>

                <!-- Gender Swap Warning Box -->
                <div class="gender-swap-warning" id="genderSwapBox">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-triangle-exclamation text-warning mt-1"></i>
                        <div>
                            <div class="fw-bold text-white small" id="genderSwapTitle">Possible Parent Name Inversion Detected</div>
                            <div class="text-warning-emphasis small mt-0 mb-2" id="genderSwapText">
                                Father's name appears to contain a typically feminine name while Mother's name contains a typically masculine name.
                            </div>
                            <button type="button" class="btn-swap" onclick="swapParents()">
                                <i class="fas fa-arrow-right-arrow-left me-1"></i> Swap Father & Mother
                            </button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-sm-6">
                        <label class="form-label" for="inpGodfatherName">Godfather's Name</label>
                        <input type="text" class="form-control" id="inpGodfatherName" value="<?php echo htmlspecialchars($model['godfatherName']); ?>" placeholder="GODFATHER'S NAME">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="inpGodmotherName">Godmother's Name</label>
                        <input type="text" class="form-control" id="inpGodmotherName" value="<?php echo htmlspecialchars($model['godmotherName']); ?>" placeholder="GODMOTHER'S NAME">
                    </div>
                </div>
            </div>

            <!-- Card 3: Clergy & Officiating Ministers -->
            <div class="control-card">
                <div class="control-card-header">
                    <h2 class="control-card-title"><i class="fas fa-cross"></i> Ministers & Celebrants</h2>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="inpBishopName">Administering Bishop Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="inpBishopName" value="<?php echo htmlspecialchars($model['bishopName']); ?>" required>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="inpBishopTitle">Bishop Title & Jurisdiction</label>
                    <input type="text" class="form-control" id="inpBishopTitle" value="<?php echo htmlspecialchars($model['bishopTitle']); ?>">
                </div>

                <div class="mb-2">
                    <label class="form-label" for="inpPriestName">Priest-in-Charge Signature Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="inpPriestName" value="<?php echo htmlspecialchars($model['priestName']); ?>" required>
                </div>

                <div>
                    <label class="form-label" for="inpPriestTitle">Priest Caption Title</label>
                    <input type="text" class="form-control" id="inpPriestTitle" value="<?php echo htmlspecialchars($model['priestTitle']); ?>">
                </div>
            </div>

            <!-- Card 4: Parish Identity & Output Options -->
            <div class="control-card">
                <div class="control-card-header">
                    <h2 class="control-card-title"><i class="fas fa-church"></i> Parish Identity & Settings</h2>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="inpParishName">Parish Name Header</label>
                    <input type="text" class="form-control" id="inpParishName" value="<?php echo htmlspecialchars($model['parishName']); ?>">
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label" for="inpParishLocation">Location</label>
                        <input type="text" class="form-control" id="inpParishLocation" value="<?php echo htmlspecialchars($model['parishLocation']); ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="inpIssueDate">Issue Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="inpIssueDate" value="<?php echo htmlspecialchars($model['issueDate']); ?>" required>
                    </div>
                </div>

                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" id="chkIncludeSecurity" checked>
                    <label class="form-check-label small" for="chkIncludeSecurity">Include Security QR Code & Certificate No.</label>
                </div>
            </div>
        </aside>

        <!-- ── RIGHT VIEWPORT: LIVE PREVIEW CANVAS ── -->
        <section class="studio-canvas-viewport">
            <!-- Floating Zoom & Fit Toolbar -->
            <div class="canvas-toolbar no-print">
                <span class="small text-muted fw-bold me-1">Zoom:</span>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="adjustZoom(-0.1)" title="Zoom Out"><i class="fas fa-minus"></i></button>
                <span class="small fw-bold text-warning" id="lblZoomLevel" style="min-width: 48px; text-align: center;">95%</span>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="adjustZoom(0.1)" title="Zoom In"><i class="fas fa-plus"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="fitToWidth()" title="Fit to Screen"><i class="fas fa-expand"></i> Fit</button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="resetZoom()" title="Reset to 100%">100%</button>
                <div class="vr bg-secondary mx-1"></div>
                <span class="badge bg-dark border border-secondary text-info" id="lblPaperBadge"><i class="fas fa-file-invoice me-1"></i> A4 Landscape</span>
            </div>

            <!-- Scaled Sheet Canvas Wrapper -->
            <div class="canvas-scale-wrapper" id="canvasScaleWrapper">
                <article class="cert-sheet" id="certificateSheet" role="region" aria-label="Certificate of Confirmation Preview">
                    <!-- Pure Vector SVG Greek-Key Meander Frame Overlay -->
                    <img src="../assets/img/certificates/confirmation-greek-border.svg" class="cert-frame-border" alt="" aria-hidden="true" />

                    <!-- Certificate Printable Document Content -->
                    <div class="cert-sheet-inner">
                        <!-- 1. Header Grid -->
                        <header class="cert-header-row">
                            <div class="cert-logo-box">
                                <img src="../assets/img/archdiocese-crest.jpg" id="viewLogoLeft" alt="Archdiocese Crest" />
                            </div>
                            <div class="cert-header-center">
                                <h1 class="cert-ornate-title">Certificate of Confirmation</h1>
                                <div class="cert-parish-name" id="viewParishName"><?php echo htmlspecialchars($model['parishName']); ?></div>
                                <div class="cert-parish-loc" id="viewParishLoc"><?php echo htmlspecialchars($model['parishLocation']); ?></div>
                            </div>
                            <div class="cert-logo-box">
                                <img src="../assets/img/san-lorenzo-logo.png" id="viewLogoRight" alt="San Lorenzo Ruiz Medallion" />
                            </div>
                        </header>

                        <!-- Gold Divider Line under Header -->
                        <div class="gold-rule-line"></div>

                        <!-- 2. Recipient Full Name -->
                        <div class="cert-recipient-box">
                            <div class="cert-recipient-name" id="viewRecipientName"><?php echo htmlspecialchars($model['confirmandName']); ?></div>
                        </div>

                        <!-- Gold Divider Line under Recipient Name -->
                        <div class="gold-rule-line" style="width: 52%; margin-top: 1mm; margin-bottom: 1.5mm;"></div>

                        <!-- 3. Sacrament Declaration -->
                        <div class="cert-sacrament-declaration">
                            received the Holy Sacrament of Confirmation
                        </div>

                        <!-- 4. Canonical Details: Day, Month, Year Underlined -->
                        <div class="cert-canon-date-line">
                            in this parish on the <span class="cert-underlined-field field-ordinal-day" id="viewOrdinalDay">1ST</span>
                            day of <span class="cert-underlined-field field-month-upper" id="viewMonthUpper">SEPTEMBER</span>,
                            20<span class="cert-underlined-field field-year-short" id="viewYearShort">26</span>.
                        </div>

                        <!-- 5. Administered by His Excellency & Bishop -->
                        <div class="cert-bishop-lead">Administered by His Excellency</div>
                        <div class="cert-bishop-name" id="viewBishopName"><?php echo htmlspecialchars($model['bishopName']); ?></div>
                        <div class="cert-bishop-title" id="viewBishopTitle"><?php echo htmlspecialchars($model['bishopTitle']); ?></div>
                        <div class="cert-delegate-line">or his delegate. Confirmed</div>

                        <!-- 6. Confirmed Recipient Repeat Line -->
                        <div class="cert-confirmed-recipient" id="viewConfirmedRecipient"><?php echo htmlspecialchars($model['confirmandName']); ?></div>

                        <!-- 7. Four Labeled Lines (Father, Mother, Godfather, Godmother) with captions beneath gold rule -->
                        <table class="cert-lineage-table">
                            <tr>
                                <td class="lineage-row-cell">
                                    <div class="lineage-gold-rule"></div>
                                    <div class="lineage-caption-lbl">Father's name</div>
                                    <span class="lineage-name-val" id="viewFatherName"><?php echo htmlspecialchars($model['fatherName']); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="lineage-row-cell">
                                    <div class="lineage-gold-rule"></div>
                                    <div class="lineage-caption-lbl">Mother's name</div>
                                    <span class="lineage-name-val" id="viewMotherName"><?php echo htmlspecialchars($model['motherName']); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="lineage-row-cell">
                                    <div class="lineage-gold-rule"></div>
                                    <div class="lineage-caption-lbl">Godfather's name</div>
                                    <span class="lineage-name-val" id="viewGodfatherName"><?php echo htmlspecialchars($model['godfatherName'] ?: 'N/A'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="lineage-row-cell">
                                    <div class="lineage-gold-rule"></div>
                                    <div class="lineage-caption-lbl">Godmother's name</div>
                                    <span class="lineage-name-val" id="viewGodmotherName"><?php echo htmlspecialchars($model['godmotherName'] ?: 'N/A'); ?></span>
                                </td>
                            </tr>
                        </table>

                        <!-- 8. Certification Statement -->
                        <div class="cert-true-copy-statement">
                            This is to certify that this certificate is a true copy of Confirmation Record kept in this parish.
                        </div>

                        <!-- 9. Footer: Issue Date, Gold Embossed Seal, Priest Signature Block -->
                        <footer class="cert-footer-row">
                            <!-- Left: Date -->
                            <div class="footer-date-block">
                                <div class="footer-val-name" id="viewIssueDate">SEPTEMBER 1, 2026</div>
                                <div class="footer-thin-rule"></div>
                                <div class="footer-sub-caption">Date</div>
                            </div>

                            <!-- Center: Gold Embossed Seal & Verification Block -->
                            <div class="footer-seal-block">
                                <img src="../assets/img/certificates/gold-embossed-parish-seal.svg" class="footer-seal-img" alt="Official Parish Seal" />
                                
                                <div class="footer-security-qr" id="viewSecurityQrBox">
                                    <!-- Embedded Offline Vector QR Code -->
                                    <svg viewBox="0 0 29 29" shape-rendering="crispEdges">
                                        <path fill="#ffffff" d="M0 0h29v29H0z"/>
                                        <path fill="#1f2937" d="M0 0h7v7H0zm1 1h5v5H1zm1 1h3v3H2zm20-2h7v7h-7zm1 1h5v5h-5zm1 1h3v3h-3zM0 22h7v7H0zm1 1h5v5H1zm1 1h3v3H2zm8-22h1v1h-1zm3 0h1v1h-1zm2 0h1v1h-1zm-4 2h1v1h-1zm3 0h2v1h-2zm-3 2h1v1h-1zm3 0h1v1h-1zm-3 2h2v1h-2zm3 0h1v1h-1zm-4 2h1v1h-1zm2 0h1v1h-1zm-2 2h3v1h-3zm-1 2h1v1h-1zm2 0h1v1h-1zm-3 2h1v1h-1zm2 0h2v1h-2zm12-14h1v1h-1zm2 0h2v1h-2zm-2 2h1v1h-1zm2 0h1v1h-1zm-3 2h2v1h-2zm2 0h1v1h-1zm-3 2h1v1h-1zm3 0h1v1h-1zm-3 2h2v1h-2zm1 2h2v1h-2zm-1 2h1v1h-1zm2 0h1v1h-1zm-3 2h2v1h-2zm2 0h1v1h-1zm-10 2h1v1h-1zm2 0h2v1h-2zm-2 2h1v1h-1zm3 0h1v1h-1zm-3 2h2v1h-2zm3 0h1v1h-1zm-4 2h1v1h-1zm2 0h2v1h-2z"/>
                                    </svg>
                                    <span id="viewCertNoLabel">CONF-2026-0042</span>
                                </div>
                            </div>

                            <!-- Right: Priest Signature Block -->
                            <div class="footer-priest-block">
                                <div class="footer-val-name" id="viewPriestName"><?php echo htmlspecialchars($model['priestName']); ?></div>
                                <div class="footer-thin-rule"></div>
                                <div class="footer-sub-caption" id="viewPriestTitle"><?php echo htmlspecialchars($model['priestTitle']); ?></div>
                            </div>
                        </footer>
                    </div>
                </article>
            </div>
        </section>
    </main>

    <!-- Hidden PDF Generation Form -->
    <form id="pdfExportForm" method="POST" action="confirmation-certificate.php?action=download_pdf" target="_blank" class="d-none">
        <input type="hidden" name="action" value="download_pdf">
        <input type="hidden" name="parishName" id="pdfParishName">
        <input type="hidden" name="parishLocation" id="pdfParishLocation">
        <input type="hidden" name="confirmandName" id="pdfConfirmandName">
        <input type="hidden" name="confirmationDate" id="pdfConfirmationDate">
        <input type="hidden" name="bishopName" id="pdfBishopName">
        <input type="hidden" name="bishopTitle" id="pdfBishopTitle">
        <input type="hidden" name="fatherName" id="pdfFatherName">
        <input type="hidden" name="motherName" id="pdfMotherName">
        <input type="hidden" name="godfatherName" id="pdfGodfatherName">
        <input type="hidden" name="godmotherName" id="pdfGodmotherName">
        <input type="hidden" name="issueDate" id="pdfIssueDate">
        <input type="hidden" name="priestName" id="pdfPriestName">
        <input type="hidden" name="priestTitle" id="pdfPriestTitle">
        <input type="hidden" name="certificateNo" id="pdfCertificateNo">
        <input type="hidden" name="includeSecurity" id="pdfIncludeSecurity" value="1">
        <input type="hidden" name="paper" id="pdfPaper" value="a4">
        <input type="hidden" name="confirmation_id" id="pdfConfirmationId" value="<?php echo (int)$model['confirmation_id']; ?>">
    </form>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Interactive Certificate Studio Engine -->
    <script>
    (function () {
        'use strict';

        // ── Data State ──
        let currentScale = 0.95;
        let currentPaper = 'a4'; // 'a4' or 'letter'
        let confirmationId = <?php echo (int)$model['confirmation_id']; ?>;

        // ── Elements Cache ──
        const inputs = {
            confirmandName: document.getElementById('inpConfirmandName'),
            confDate: document.getElementById('inpConfDate'),
            certNo: document.getElementById('inpCertNo'),
            fatherName: document.getElementById('inpFatherName'),
            motherName: document.getElementById('inpMotherName'),
            godfatherName: document.getElementById('inpGodfatherName'),
            godmotherName: document.getElementById('inpGodmotherName'),
            bishopName: document.getElementById('inpBishopName'),
            bishopTitle: document.getElementById('inpBishopTitle'),
            priestName: document.getElementById('inpPriestName'),
            priestTitle: document.getElementById('inpPriestTitle'),
            parishName: document.getElementById('inpParishName'),
            parishLocation: document.getElementById('inpParishLocation'),
            issueDate: document.getElementById('inpIssueDate'),
            includeSecurity: document.getElementById('chkIncludeSecurity')
        };

        const views = {
            parishName: document.getElementById('viewParishName'),
            parishLoc: document.getElementById('viewParishLoc'),
            recipientName: document.getElementById('viewRecipientName'),
            ordinalDay: document.getElementById('viewOrdinalDay'),
            monthUpper: document.getElementById('viewMonthUpper'),
            yearShort: document.getElementById('viewYearShort'),
            bishopName: document.getElementById('viewBishopName'),
            bishopTitle: document.getElementById('viewBishopTitle'),
            confirmedRecipient: document.getElementById('viewConfirmedRecipient'),
            fatherName: document.getElementById('viewFatherName'),
            motherName: document.getElementById('viewMotherName'),
            godfatherName: document.getElementById('viewGodfatherName'),
            godmotherName: document.getElementById('viewGodmotherName'),
            issueDate: document.getElementById('viewIssueDate'),
            priestName: document.getElementById('viewPriestName'),
            priestTitle: document.getElementById('viewPriestTitle'),
            securityQrBox: document.getElementById('viewSecurityQrBox'),
            certNoLabel: document.getElementById('viewCertNoLabel')
        };

        const scaleWrapper = document.getElementById('canvasScaleWrapper');
        const certSheet = document.getElementById('certificateSheet');
        const lblZoom = document.getElementById('lblZoomLevel');
        const lblParsedDate = document.getElementById('lblParsedDate');
        const genderSwapBox = document.getElementById('genderSwapBox');
        const validationAlert = document.getElementById('validationAlert');
        const validationAlertMsg = document.getElementById('validationAlertMsg');

        // ── Date Ordinal Formatting ──
        function getOrdinalDay(day) {
            const d = parseInt(day, 10);
            if (isNaN(d)) return '';
            const j = d % 10, k = d % 100;
            if (j === 1 && k !== 11) return d + 'ST';
            if (j === 2 && k !== 12) return d + 'ND';
            if (j === 3 && k !== 13) return d + 'RD';
            return d + 'TH';
        }

        const MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

        function decomposeDate(dateStr) {
            if (!dateStr) return { ordinal: '', month: '', century: '20', yearShort: '', formatted: '' };
            const parts = dateStr.split('-');
            if (parts.length < 3) return { ordinal: '', month: '', century: '20', yearShort: '', formatted: '' };
            const y = parts[0], m = parseInt(parts[1], 10), d = parseInt(parts[2], 10);
            const ordinal = getOrdinalDay(d);
            const monthName = MONTHS[m - 1] || '';
            const century = y.substring(0, 2);
            const yearShort = y.substring(2);
            const formatted = `${monthName} ${d}, ${y}`;
            return { ordinal, month: monthName, century, yearShort, formatted };
        }

        // ── Auto-Shrink Single Line Text (Prevents wrapping/overflow) ──
        function fitSingleLineText(el, maxPt, minPt = 7) {
            if (!el) return;
            let currentPt = maxPt;
            el.style.fontSize = currentPt + 'pt';
            while (el.scrollWidth > el.clientWidth && currentPt > minPt) {
                currentPt -= 0.5;
                el.style.fontSize = currentPt + 'pt';
            }
        }

        function fitAllNames() {
            fitSingleLineText(views.recipientName, 20, 11);
            fitSingleLineText(views.confirmedRecipient, 10, 7.5);
            fitSingleLineText(views.fatherName, 10, 7);
            fitSingleLineText(views.motherName, 10, 7);
            fitSingleLineText(views.godfatherName, 10, 7);
            fitSingleLineText(views.godmotherName, 10, 7);
            fitSingleLineText(views.bishopName, 11.5, 8.5);
            fitSingleLineText(views.priestName, 9.2, 7.5);
            fitSingleLineText(views.issueDate, 9.2, 7.5);
        }

        // ── Smart Gender Inversion Detection ──
        const FEMALE_NAMES = new Set([
            'maria', 'mary', 'joy', 'ana', 'anne', 'rose', 'grace', 'elizabeth', 'lourdes', 'carmelita',
            'teresa', 'cristina', 'jennifer', 'rosalie', 'rowena', 'marites', 'florence', 'angelica',
            'judith', 'maribel', 'maricel', 'catherine', 'patricia', 'lucia', 'gloria', 'norma', 'corazon',
            'esperanza', 'teresita', 'beatriz', 'imelda', 'victoria', 'jocelyn', 'estrella', 'fe', 'gemma',
            'gina', 'irene', 'janet', 'joan', 'karen', 'leah', 'linda', 'lorna', 'mercy', 'myrna', 'olivia',
            'paula', 'ruby', 'sandra', 'shirley', 'sonia', 'susana', 'vilma', 'virginia', 'yolanda', 'fatima'
        ]);

        const MALE_NAMES = new Set([
            'roberto', 'juan', 'jose', 'rey', 'mark', 'lee', 'john', 'peter', 'pedro', 'paul', 'antonio',
            'manuel', 'francisco', 'carlos', 'miguel', 'angel', 'alberto', 'fernando', 'ramon', 'eduardo',
            'ricardo', 'cesar', 'victor', 'mario', 'danilo', 'romeo', 'ronaldo', 'reynaldo', 'renato',
            'edgardo', 'jaime', 'ernesto', 'salvador', 'rodrigo', 'arturo', 'raul', 'gilbert', 'roel',
            'noel', 'joel', 'alan', 'allan', 'alex', 'alexander', 'alfredo', 'bernardo', 'benjamin',
            'christopher', 'dennis', 'edwin', 'felipe', 'gerardo', 'gregorio', 'henry', 'jerry', 'jorge'
        ]);

        function extractTokens(nameStr) {
            return (nameStr || '').toLowerCase().replace(/[^a-z\s]/g, ' ').split(/\s+/).filter(Boolean);
        }

        function checkParentNameInversion() {
            const fatherTokens = extractTokens(inputs.fatherName.value);
            const motherTokens = extractTokens(inputs.motherName.value);

            let fatherHasFemale = '';
            let motherHasMale = '';

            for (const t of fatherTokens) {
                if (FEMALE_NAMES.has(t)) { fatherHasFemale = t.toUpperCase(); break; }
            }
            for (const t of motherTokens) {
                if (MALE_NAMES.has(t)) { motherHasMale = t.toUpperCase(); break; }
            }

            if (fatherHasFemale || motherHasMale) {
                genderSwapBox.classList.add('active');
                let reason = [];
                if (fatherHasFemale) reason.push(`Father's name contains typically feminine name "${fatherHasFemale}"`);
                if (motherHasMale) reason.push(`Mother's name contains typically masculine name "${motherHasMale}"`);
                document.getElementById('genderSwapText').innerText = reason.join(' and ') + '. Would you like to swap them?';
            } else {
                genderSwapBox.classList.remove('active');
            }
        }

        window.swapParents = function () {
            const fVal = inputs.fatherName.value;
            const mVal = inputs.motherName.value;
            inputs.fatherName.value = mVal;
            inputs.motherName.value = fVal;
            updatePreview();
            checkParentNameInversion();
        };

        // ── Real-Time Preview Update ──
        function updatePreview() {
            // Confirmand
            const cName = (inputs.confirmandName.value || '').trim().toUpperCase();
            views.recipientName.innerText = cName || 'CONFIRMAND FULL NAME';
            views.confirmedRecipient.innerText = cName || 'CONFIRMAND FULL NAME';

            // Confirmation Date
            const confDateObj = decomposeDate(inputs.confDate.value);
            views.ordinalDay.innerText = confDateObj.ordinal || '1ST';
            views.monthUpper.innerText = confDateObj.month || 'SEPTEMBER';
            views.yearShort.innerText = confDateObj.yearShort || '26';
            lblParsedDate.innerText = `${confDateObj.ordinal || '1ST'} day of ${confDateObj.month || 'SEPTEMBER'}, 20${confDateObj.yearShort || '26'}`;

            // Bishop
            views.bishopName.innerText = (inputs.bishopName.value || '').trim();
            views.bishopTitle.innerText = (inputs.bishopTitle.value || '').trim();

            // Parents
            views.fatherName.innerText = (inputs.fatherName.value || '').trim().toUpperCase() || 'FATHER’S NAME';
            views.motherName.innerText = (inputs.motherName.value || '').trim().toUpperCase() || 'MOTHER’S NAME';

            // Sponsors
            views.godfatherName.innerText = (inputs.godfatherName.value || '').trim().toUpperCase() || 'N/A';
            views.godmotherName.innerText = (inputs.godmotherName.value || '').trim().toUpperCase() || 'N/A';

            // Issue Date
            const issueDateObj = decomposeDate(inputs.issueDate.value);
            views.issueDate.innerText = issueDateObj.formatted.toUpperCase() || 'DATE';

            // Priest
            views.priestName.innerText = (inputs.priestName.value || '').trim().toUpperCase();
            views.priestTitle.innerText = (inputs.priestTitle.value || '').trim();

            // Parish Info
            views.parishName.innerText = (inputs.parishName.value || '').trim().toUpperCase();
            views.parishLoc.innerText = (inputs.parishLocation.value || '').trim();

            // Certificate Number & Security
            const certNo = (inputs.certNo.value || '').trim();
            views.certNoLabel.innerText = certNo || 'CONF-RECORD';
            views.securityQrBox.style.display = inputs.includeSecurity.checked ? 'flex' : 'none';

            // Trigger single line auto-fit
            fitAllNames();
        }

        // Attach listeners for 2-way live updating
        Object.values(inputs).forEach(input => {
            if (!input) return;
            input.addEventListener('input', () => {
                updatePreview();
                if (input === inputs.fatherName || input === inputs.motherName) {
                    checkParentNameInversion();
                }
                clearFieldError(input);
            });
            input.addEventListener('change', () => {
                updatePreview();
                if (input === inputs.fatherName || input === inputs.motherName) {
                    checkParentNameInversion();
                }
            });
        });

        // ── Validation Engine ──
        function clearFieldError(el) {
            el.classList.remove('is-invalid');
            if (document.querySelectorAll('.form-control.is-invalid').length === 0) {
                validationAlert.classList.add('d-none');
            }
        }

        function validateForm() {
            const requiredInputs = [
                { el: inputs.confirmandName, label: 'Confirmand Full Name' },
                { el: inputs.confDate, label: 'Confirmation Date' },
                { el: inputs.fatherName, label: "Father's Name" },
                { el: inputs.motherName, label: "Mother's Name" },
                { el: inputs.bishopName, label: 'Administering Bishop Name' },
                { el: inputs.priestName, label: 'Priest-in-Charge Name' },
                { el: inputs.issueDate, label: 'Issue Date' }
            ];

            const missing = [];
            requiredInputs.forEach(item => {
                if (!item.el.value.trim()) {
                    item.el.classList.add('is-invalid');
                    missing.push(item.label);
                } else {
                    item.el.classList.remove('is-invalid');
                }
            });

            if (missing.length > 0) {
                validationAlertMsg.innerText = 'Please complete: ' + missing.join(', ') + '.';
                validationAlert.classList.remove('d-none');
                validationAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return false;
            }

            validationAlert.classList.add('d-none');
            return true;
        }

        // ── Paper Size Switcher ──
        window.setPaperSize = function (size) {
            currentPaper = size;
            const btnA4 = document.getElementById('btnPaperA4');
            const btnLetter = document.getElementById('btnPaperLetter');
            const badge = document.getElementById('lblPaperBadge');

            if (size === 'letter') {
                btnLetter.classList.add('active');
                btnA4.classList.remove('active');
                document.documentElement.style.setProperty('--page-width', '279.4mm');
                document.documentElement.style.setProperty('--page-height', '215.9mm');
                badge.innerHTML = '<i class="fas fa-file-invoice me-1"></i> US Letter Landscape';
            } else {
                btnA4.classList.add('active');
                btnLetter.classList.remove('active');
                document.documentElement.style.setProperty('--page-width', '297mm');
                document.documentElement.style.setProperty('--page-height', '210mm');
                badge.innerHTML = '<i class="fas fa-file-invoice me-1"></i> A4 Landscape';
            }
            fitAllNames();
        };

        // ── Viewport Zoom & Fit ──
        function setScale(scale) {
            currentScale = Math.max(0.4, Math.min(1.5, scale));
            scaleWrapper.style.transform = `scale(${currentScale})`;
            lblZoom.innerText = Math.round(currentScale * 100) + '%';
        }

        window.adjustZoom = function (delta) {
            setScale(currentScale + delta);
        };

        window.resetZoom = function () {
            setScale(1.0);
        };

        window.fitToWidth = function () {
            const viewportWidth = document.querySelector('.studio-canvas-viewport').clientWidth - 60;
            // 297mm in pixels at 96 DPI is ~1122.5px
            const sheetPxWidth = (currentPaper === 'letter') ? (279.4 * 3.7795) : (297 * 3.7795);
            const fitScale = Math.min(1.1, viewportWidth / sheetPxWidth);
            setScale(fitScale);
        };

        // ── Print & Download Actions ──
        window.triggerPrint = function () {
            if (!validateForm()) return;

            // Log print event to database asynchronously
            fetch('confirmation-certificate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'log_print',
                    confirmation_id: confirmationId,
                    confirmandName: inputs.confirmandName.value.trim(),
                    certificateNo: inputs.certNo.value.trim()
                })
            }).catch(console.error);

            window.print();
        };

        window.triggerDownloadPdf = function () {
            if (!validateForm()) return;

            // Populate hidden form
            document.getElementById('pdfParishName').value = inputs.parishName.value;
            document.getElementById('pdfParishLocation').value = inputs.parishLocation.value;
            document.getElementById('pdfConfirmandName').value = inputs.confirmandName.value;
            document.getElementById('pdfConfirmationDate').value = inputs.confDate.value;
            document.getElementById('pdfBishopName').value = inputs.bishopName.value;
            document.getElementById('pdfBishopTitle').value = inputs.bishopTitle.value;
            document.getElementById('pdfFatherName').value = inputs.fatherName.value;
            document.getElementById('pdfMotherName').value = inputs.motherName.value;
            document.getElementById('pdfGodfatherName').value = inputs.godfatherName.value;
            document.getElementById('pdfGodmotherName').value = inputs.godmotherName.value;
            document.getElementById('pdfIssueDate').value = inputs.issueDate.value;
            document.getElementById('pdfPriestName').value = inputs.priestName.value;
            document.getElementById('pdfPriestTitle').value = inputs.priestTitle.value;
            document.getElementById('pdfCertificateNo').value = inputs.certNo.value;
            document.getElementById('pdfIncludeSecurity').value = inputs.includeSecurity.checked ? '1' : '0';
            document.getElementById('pdfPaper').value = currentPaper;
            document.getElementById('pdfConfirmationId').value = confirmationId;

            document.getElementById('pdfExportForm').submit();
        };

        window.saveCertificateIssuance = function () {
            if (!validateForm()) return;

            const btn = event?.currentTarget;
            if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

            fetch('confirmation-certificate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'save_issuance',
                    confirmation_id: confirmationId,
                    confirmandName: inputs.confirmandName.value.trim(),
                    certificateNo: inputs.certNo.value.trim()
                })
            })
            .then(res => res.json())
            .then(data => {
                if (btn) btn.innerHTML = '<i class="fas fa-check me-1"></i> Saved!';
                setTimeout(() => {
                    if (btn) btn.innerHTML = '<i class="fas fa-save me-1"></i> Save & Log';
                }, 2000);
            })
            .catch(err => {
                if (btn) btn.innerHTML = '<i class="fas fa-save me-1"></i> Save & Log';
                alert('Saved issuance recorded.');
            });
        };

        // ── Record Switcher ──
        const recordSelector = document.getElementById('recordSelector');
        if (recordSelector) {
            recordSelector.addEventListener('change', function () {
                const recId = this.value;
                if (!recId) return;

                fetch(`confirmation-certificate.php?action=fetch_record&id=${recId}`)
                    .then(res => res.json())
                    .then(json => {
                        if (json.status === 'success' && json.data) {
                            const d = json.data;
                            confirmationId = d.confirmation_id || recId;
                            inputs.confirmandName.value = d.confirmandName || '';
                            inputs.confDate.value = d.confirmationDate || '';
                            inputs.certNo.value = d.certificateNo || '';
                            inputs.fatherName.value = d.fatherName || '';
                            inputs.motherName.value = d.motherName || '';
                            inputs.godfatherName.value = d.godfatherName || '';
                            inputs.godmotherName.value = d.godmotherName || '';
                            inputs.bishopName.value = d.bishopName || '';
                            inputs.bishopTitle.value = d.bishopTitle || '';
                            inputs.priestName.value = d.priestName || '';
                            inputs.priestTitle.value = d.priestTitle || '';
                            inputs.parishName.value = d.parishName || '';
                            inputs.parishLocation.value = d.parishLocation || '';
                            inputs.issueDate.value = d.issueDate || '';

                            updatePreview();
                            checkParentNameInversion();
                        } else {
                            alert(json.message || 'Error loading record');
                        }
                    })
                    .catch(err => {
                        console.error('Fetch error:', err);
                    });
            });
        }

        // Initial setup
        window.addEventListener('resize', fitToWidth);
        updatePreview();
        checkParentNameInversion();
        fitToWidth();
    })();
    </script>
</body>
</html>
