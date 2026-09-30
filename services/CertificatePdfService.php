<?php
/**
 * Certificate PDF & Generation Service
 *
 * Modular, reusable engine for generating print-ready sacramental certificates
 * (Baptism, Confirmation, Marriage, etc.) with strict field validation,
 * dynamic database population, and Dompdf rendering.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class CertificatePdfService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Required fields for an official Certificate of Baptism.
     * Generation must be blocked if any of these are missing.
     */
    public static function requiredBaptismFields(): array
    {
        return [
            'fullname' => 'Name',
            'birth_place' => 'Birthplace',
            'birth_date' => 'Birthday',
            'parent_address' => 'Residence',
            'father_name' => "Father's Name",
            'father_birth_place' => "Father's Birthplace",
            'mother_name' => "Mother's Name",
            'mother_birth_place' => "Mother's Birthplace",
            'baptism_date' => 'Date of Baptism',
            'priest' => 'Officiating Priest',
            'godparents' => 'Sponsors / Godparents',
        ];
    }

    /**
     * Validates whether a Baptism record has all required fields.
     * Returns an array of missing field labels (empty if fully valid).
     */
    public function validateBaptismRecord(array $record): array
    {
        $missing = [];
        $fullname = trim((string)($record['fullname'] ?? ''));
        if ($fullname === '' || $fullname === 'N/A') {
            $missing[] = 'Full Name';
        }

        $birth_place = trim((string)($record['birth_place'] ?? ''));
        if ($birth_place === '' || $birth_place === 'N/A') {
            $missing[] = 'Birthplace';
        }

        $bdate = trim((string)($record['birth_date'] ?? ''));
        if ($bdate === '' || $bdate === '0000-00-00' || $bdate === 'N/A') {
            $missing[] = 'Birthday';
        }

        $residence = trim((string)($record['parent_address'] ?? ($record['residence'] ?? ($record['parish_address'] ?? ''))));
        if ($residence === '' || $residence === 'N/A') {
            $missing[] = 'Residence';
        }

        $father = trim((string)($record['father_name'] ?? ''));
        if ($father === '' || $father === 'N/A') {
            $missing[] = "Father's Name";
        }

        $father_bp = trim((string)($record['father_birth_place'] ?? ''));
        if ($father_bp === '' || $father_bp === 'N/A') {
            $missing[] = "Father's Birthplace";
        }

        $mother = trim((string)($record['mother_name'] ?? ''));
        if ($mother === '' || $mother === 'N/A') {
            $missing[] = "Mother's Name";
        }

        $mother_bp = trim((string)($record['mother_birth_place'] ?? ''));
        if ($mother_bp === '' || $mother_bp === 'N/A') {
            $missing[] = "Mother's Birthplace";
        }

        $bpdate = trim((string)($record['baptism_date'] ?? ''));
        if ($bpdate === '' || $bpdate === '0000-00-00' || $bpdate === 'N/A') {
            $missing[] = 'Date of Baptism';
        }

        $priest = trim((string)($record['priest'] ?? ($record['officiating_priest'] ?? '')));
        if ($priest === '' || $priest === 'N/A' || strcasecmp($priest, 'Rev. Fr. Parish Priest') === 0) {
            $missing[] = 'Officiating Priest';
        }

        $sponsors = self::parseSponsors($record['godparents'] ?? ($record['sponsors'] ?? ''));
        if (empty($sponsors)) {
            $missing[] = 'Sponsors (Godparents)';
        }

        return $missing;
    }

    /**
     * Splits sponsors into multiple lines (one name per line).
     */
    public static function parseSponsors($raw): array
    {
        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                $item = trim((string)$item);
                if ($item !== '' && !in_array($item, $list, true)) {
                    $list[] = $item;
                }
            }
            return $list;
        }

        $raw = trim((string)$raw);
        if ($raw === '' || $raw === 'N/A') {
            return [];
        }

        // Split by newlines first
        $lines = preg_split('/[\r\n]+/', $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Also split comma / and / semicolon if separated
            $parts = preg_split('/,\s*|\s+and\s+|\s*;\s*|\s*\/\s*/i', $line);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $list, true)) {
                    $list[] = $p;
                }
            }
        }

        return $list;
    }

    /**
     * Retrieve the Priest-in-Charge Name configured in Parish Settings.
     */
    public function getPriestInChargeName(): string
    {
        try {
            if ($this->db instanceof mysqli) {
                $stmt = @$this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('parish.priest_in_charge', 'parish_priest_name') AND setting_value IS NOT NULL AND setting_value != '' ORDER BY CASE WHEN setting_key = 'parish.priest_in_charge' THEN 1 ELSE 2 END LIMIT 1");
                if ($stmt) {
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row && !empty(trim((string)$row['setting_value']))) {
                        return trim((string)$row['setting_value']);
                    }
                }
            }
        } catch (\Throwable $e) {}
        return 'REV. FR. HERIBERTO C. VILLAS, O.M.I.';
    }

    /**
     * Retrieve the Priest-in-Charge Title configured in Parish Settings.
     */
    public function getPriestInChargeTitle(): string
    {
        try {
            if ($this->db instanceof mysqli) {
                $stmt = @$this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'parish.priest_in_charge_title' LIMIT 1");
                if ($stmt) {
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row && !empty(trim((string)$row['setting_value']))) {
                        return trim((string)$row['setting_value']);
                    }
                }
            }
        } catch (\Throwable $e) {}
        return 'Priest-in-Charge';
    }

    /**
     * Formats a unique Control/Security Number for the certificate.
     */
    public function getControlNumber(array $record): string
    {
        $reqId = intval($record['request_id'] ?? 0);
        if ($reqId > 0) {
            $stmt = $this->db->prepare("SELECT reference_number FROM requests WHERE request_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $reqId);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($res && !empty($res['reference_number'])) {
                    return $res['reference_number'];
                }
            }
        }

        $bapId = intval($record['baptism_id'] ?? 0);
        $bapYear = !empty($record['baptism_date']) ? date('Y', strtotime($record['baptism_date'])) : date('Y');
        return sprintf('TUGON-BAP-%s-%04d', $bapYear, $bapId);
    }

    /**
     * Converts a local asset file to base64 Data URI for robust embedding in HTML/PDF.
     */
    public static function fileToDataUri(string $filePath, string $mime = ''): string
    {
        if (!file_exists($filePath)) {
            return '';
        }
        $data = file_get_contents($filePath);
        if ($data === false) {
            return '';
        }
        if (empty($mime)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $map = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'svg' => 'image/svg+xml',
                'webp' => 'image/webp',
            ];
            $mime = $map[$ext] ?? 'application/octet-stream';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     * Renders the complete, official HTML template for the Certificate of Baptism.
     * Exactly matching the parish's official layout and reference design.
     */
    public function renderBaptismCertificateHtml(array $record, array $options = []): string
    {
        $root = dirname(__DIR__);
        $crestPath = $root . '/assets/img/archdiocese-crest.jpg';
        $slrPath = $root . '/assets/img/san-lorenzo-logo.jpg';
        $borderPath = $root . '/assets/img/certificates/baptism-official-border.svg';

        $crestUri = self::fileToDataUri($crestPath, 'image/jpeg');
        $slrUri = self::fileToDataUri($slrPath, 'image/jpeg');
        $borderUri = self::fileToDataUri($borderPath, 'image/svg+xml');

        $fullname = strtoupper(trim((string)($record['fullname'] ?? '')));
        $birth_place = trim((string)($record['birth_place'] ?? ''));
        $birth_date = !empty($record['birth_date']) ? date('F j, Y', strtotime($record['birth_date'])) : '';
        $residence = trim((string)($record['parent_address'] ?? ($record['residence'] ?? '')));
        $father_name = trim((string)($record['father_name'] ?? ''));
        $father_birth_place = trim((string)($record['father_birth_place'] ?? ''));
        $mother_name = trim((string)($record['mother_name'] ?? ''));
        $mother_birth_place = trim((string)($record['mother_birth_place'] ?? ''));
        $baptism_date = !empty($record['baptism_date']) ? date('F j, Y', strtotime($record['baptism_date'])) : '';

        // Officiating Priest formatting
        $priest = trim((string)($record['priest'] ?? ($record['officiating_priest'] ?? '')));
        $priest = preg_replace('/^(?:by\s+the\s+)?(?:rev\.?\s*fr\.?\s*|father\s*|fr\.?\s*)(.*)$/i', '$1', $priest);
        $priest = trim($priest);

        $sponsors = self::parseSponsors($record['godparents'] ?? ($record['sponsors'] ?? ''));
        $priestInCharge = $this->getPriestInChargeName();
        $priestInChargeTitle = $this->getPriestInChargeTitle();
        $controlNumber = $this->getControlNumber($record);

        $purpose = trim((string)($record['purpose'] ?? ''));
        $showPurpose = ($purpose !== '' && strtolower($purpose) !== 'n/a' && strtolower($purpose) !== 'whatever lawful purpose it may serve');

        // Font scaling for sponsors if long list
        $sponsorCount = count($sponsors);
        $sponsorLineClass = $sponsorCount > 4 ? 'compact-sponsors' : '';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Baptism - <?php echo htmlspecialchars($fullname); ?></title>
    <style>
        @page {
            size: letter portrait;
            margin: 0;
        }
        *, *::before, *::after {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            margin: 0;
            padding: 0;
            background: #e9e5dd;
            color: #111111;
            font-family: 'Times New Roman', Times, Georgia, serif;
        }
        .cert-outer-frame {
            width: 8.5in;
            height: 11in;
            position: relative;
            background: #fbf7ee url('<?php echo $borderUri; ?>') no-repeat center center;
            background-size: 100% 100%;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.22);
            box-sizing: border-box;
        }
        .cert-inner-content {
            position: relative;
            z-index: 10;
            padding: 58px 64px 44px 64px;
            width: 100%;
            height: 100%;
        }

        /* ── HEADER ── */
        .cert-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .cert-header-table td {
            vertical-align: middle;
            padding: 0;
        }
        .crest-cell {
            width: 82px;
            text-align: left;
        }
        .seal-cell {
            width: 82px;
            text-align: right;
        }
        .header-logo {
            width: 76px;
            height: 76px;
            object-fit: contain;
            display: inline-block;
        }
        .header-center {
            text-align: center;
            padding: 0 10px;
        }
        .header-church {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 11.5pt;
            font-weight: 700;
            color: #5c1414;
            letter-spacing: 2.2px;
            text-transform: uppercase;
            margin: 0 0 2px 0;
            line-height: 1.15;
        }
        .header-diocese {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 13.5pt;
            font-weight: 800;
            color: #5c1414;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin: 0 0 2px 0;
            line-height: 1.15;
        }
        .header-mission {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 13pt;
            font-weight: 800;
            color: #5c1414;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 0 0 2px 0;
            line-height: 1.15;
        }
        .header-location {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 10.5pt;
            font-weight: 700;
            color: #5c1414;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            margin: 0 0 6px 0;
            line-height: 1.15;
        }
        .header-title {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 21pt;
            font-weight: 800;
            color: #631818;
            letter-spacing: 3.5px;
            text-transform: uppercase;
            margin: 4px 0 2px 0;
            line-height: 1.1;
        }
        .header-cross-divider {
            text-align: center;
            font-size: 10pt;
            color: #8c6427;
            line-height: 1;
            margin: 2px 0;
        }
        .header-subtitle {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 11pt;
            font-style: italic;
            color: #4a3525;
            margin: 1px 0 0 0;
            letter-spacing: 0.5px;
        }

        /* ── BODY FIELDS (Traditional Fill-in-the-Blank Lines) ── */
        .cert-body-form {
            width: 100%;
            margin-top: 14px;
            margin-bottom: auto;
        }
        /* Table-based layout for Dompdf compatibility (flexbox not supported) */
        .cert-field-row {
            display: table;
            width: 100%;
            margin-bottom: 7.5px;
            box-sizing: border-box;
            table-layout: fixed;
        }
        .cert-field-row.indent {
            padding-left: 36px;
            width: calc(100% - 36px);
        }
        .cert-field-row.sponsor-extra {
            padding-left: 84px;
            width: calc(100% - 84px);
            margin-top: -1px;
        }
        .field-label {
            display: table-cell;
            font-family: 'Times New Roman', Times, Georgia, serif;
            font-size: 11.8pt;
            font-weight: 700;
            font-style: italic;
            color: #561212;
            white-space: nowrap;
            padding-right: 8px;
            padding-bottom: 4.5px;
            vertical-align: bottom;
            width: 1%;
            line-height: 1.2;
            box-sizing: border-box;
        }
        .field-fill-line {
            display: table-cell;
            width: 100%;
            border-bottom: 1.2px solid #561212;
            padding-left: 6px;
            padding-bottom: 4.5px;
            vertical-align: bottom;
            line-height: 1.2;
            box-sizing: border-box;
        }
        .field-value {
            font-family: 'Times New Roman', Times, Georgia, serif;
            font-size: 11.8pt;
            font-weight: 700;
            color: #111111;
            letter-spacing: 0.2px;
            white-space: nowrap;
            line-height: 1.2;
        }
        .field-value.name-value {
            font-size: 13.5pt;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        /* Compact styling if many sponsors */
        .compact-sponsors .cert-field-row {
            margin-bottom: 5px;
        }
        .compact-sponsors .field-label {
            font-size: 10.8pt;
            padding-bottom: 3.5px;
        }
        .compact-sponsors .field-fill-line {
            padding-bottom: 3.5px;
        }
        .compact-sponsors .field-value {
            font-size: 10.8pt;
        }

        .cert-purpose-text {
            font-family: 'Times New Roman', Times, Georgia, serif;
            font-size: 10.5pt;
            font-style: italic;
            color: #561212;
            text-align: center;
            margin-top: 8px;
            line-height: 1.3;
        }
        .cert-purpose-text span {
            font-family: 'Times New Roman', Times, Georgia, serif;
            font-style: normal;
            font-weight: 700;
            border-bottom: 1.2px solid #561212;
            padding: 0 6px 3px 6px;
        }

        /* ── FOOTER (Dry Seal, Cross & Priest Signature) ── */
        .cert-footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .cert-footer-table td {
            vertical-align: bottom;
            padding: 0;
        }
        .footer-seal-col {
            width: 48%;
            text-align: left;
        }
        .footer-seal-wrapper {
            display: inline-block;
        }
        .footer-seal-wrapper .dry-seal-stamp {
            margin-right: 16px;
        }
        .dry-seal-stamp {
            width: 82px;
            height: 82px;
            border: 1.5px dashed #7a2323;
            border-radius: 50%;
            display: inline-block;
            text-align: center;
            color: #7a2323;
            padding: 4px;
            box-shadow: inset 0 0 0 2px rgba(122, 35, 35, 0.15);
            vertical-align: middle;
        }
        .dry-seal-cross {
            font-size: 11pt;
            line-height: 1;
            margin-bottom: 2px;
        }
        .dry-seal-text-main {
            font-family: 'Times New Roman', serif;
            font-size: 6.8pt;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            line-height: 1.15;
        }
        .dry-seal-text-sub {
            font-family: 'Times New Roman', serif;
            font-size: 6.2pt;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .gold-cross-ornament {
            font-size: 34pt;
            color: #c59b27;
            line-height: 1;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.15);
            display: inline-block;
        }

        .footer-sig-col {
            width: 52%;
            text-align: center;
        }
        .signature-box {
            display: inline-block;
            width: 100%;
            max-width: 320px;
            text-align: center;
        }
        .signature-name-line {
            border-bottom: 1.3px solid #561212;
            padding-bottom: 2px;
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 11.5pt;
            font-weight: 800;
            color: #111111;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .signature-title-label {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 10.5pt;
            font-style: italic;
            color: #561212;
            margin-top: 3px;
        }

        /* ── SECURITY / CONTROL NUMBER ── */
        .cert-control-number {
            position: absolute;
            bottom: 24px;
            right: 48px;
            font-family: 'Courier New', monospace;
            font-size: 7pt;
            color: #8c6427;
            letter-spacing: 0.8px;
            text-align: right;
            z-index: 15;
        }

        /* ── PRINT RULES ── */
        @media print {
            body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .cert-outer-frame {
                box-shadow: none !important;
                margin: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body>
    <div class="cert-outer-frame">
        <div class="cert-inner-content">
            <!-- ── HEADER ── -->
            <table class="cert-header-table">
                <tr>
                    <td class="crest-cell">
                        <?php if ($crestUri): ?>
                            <img src="<?php echo $crestUri; ?>" alt="Archdiocese Crest" class="header-logo">
                        <?php endif; ?>
                    </td>
                    <td class="header-center">
                        <div class="header-church">ROMAN CATHOLIC CHURCH</div>
                        <div class="header-diocese">ARCHDIOCESE OF COTABATO</div>
                        <div class="header-mission">SAN LORENZO RUIZ MISSION STATION</div>
                        <div class="header-location">ALEOSAN, COTABATO</div>
                        <div class="header-title">CERTIFICATE OF BAPTISM</div>
                        <div class="header-cross-divider">❖</div>
                        <div class="header-subtitle">Issued from the Official Parish Records</div>
                    </td>
                    <td class="seal-cell">
                        <?php if ($slrUri): ?>
                            <img src="<?php echo $slrUri; ?>" alt="San Lorenzo Ruiz Medallion" class="header-logo">
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <!-- ── DYNAMIC BODY FORM ── -->
            <div class="cert-body-form <?php echo $sponsorLineClass; ?>">
                <!-- Name -->
                <div class="cert-field-row">
                    <span class="field-label">Name:</span>
                    <div class="field-fill-line">
                        <span class="field-value name-value"><?php echo htmlspecialchars($fullname); ?></span>
                    </div>
                </div>

                <!-- Birthplace (indented) -->
                <div class="cert-field-row indent">
                    <span class="field-label">Birthplace:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($birth_place); ?></span>
                    </div>
                </div>

                <!-- Birthday (indented) -->
                <div class="cert-field-row indent">
                    <span class="field-label">Birthday:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($birth_date); ?></span>
                    </div>
                </div>

                <!-- Residence (indented) -->
                <div class="cert-field-row indent">
                    <span class="field-label">Residence:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($residence); ?></span>
                    </div>
                </div>

                <!-- Father -->
                <div class="cert-field-row">
                    <span class="field-label">Father:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($father_name); ?></span>
                    </div>
                </div>

                <!-- Father's Birthplace (indented) -->
                <div class="cert-field-row indent">
                    <span class="field-label">Birthplace:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($father_birth_place); ?></span>
                    </div>
                </div>

                <!-- Mother -->
                <div class="cert-field-row">
                    <span class="field-label">Mother:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($mother_name); ?></span>
                    </div>
                </div>

                <!-- Mother's Birthplace (indented) -->
                <div class="cert-field-row indent">
                    <span class="field-label">Birthplace:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($mother_birth_place); ?></span>
                    </div>
                </div>

                <!-- Date of Baptism -->
                <div class="cert-field-row">
                    <span class="field-label">Date of Baptism:</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($baptism_date); ?></span>
                    </div>
                </div>

                <!-- Officiating Priest (indented) -->
                <div class="cert-field-row indent">
                    <span class="field-label">by the Rev. Fr.</span>
                    <div class="field-fill-line">
                        <span class="field-value"><?php echo htmlspecialchars($priest); ?></span>
                    </div>
                </div>

                <!-- Sponsors (multi-line) -->
                <?php if (!empty($sponsors)): ?>
                    <?php foreach ($sponsors as $idx => $sponsor): ?>
                        <?php if ($idx === 0): ?>
                            <div class="cert-field-row">
                                <span class="field-label">Sponsors:</span>
                                <div class="field-fill-line">
                                    <span class="field-value"><?php echo htmlspecialchars($sponsor); ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="cert-field-row sponsor-extra">
                                <div class="field-fill-line">
                                    <span class="field-value"><?php echo htmlspecialchars($sponsor); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="cert-field-row">
                        <span class="field-label">Sponsors:</span>
                        <div class="field-fill-line">
                            <span class="field-value">N/A</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($showPurpose): ?>
                    <div class="cert-purpose-text">
                        Issued upon official parish request for <span><?php echo htmlspecialchars($purpose); ?></span>.
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── FOOTER ── -->
            <table class="cert-footer-table">
                <tr>
                    <td class="footer-seal-col">
                        <div class="footer-seal-wrapper">
                            <div class="dry-seal-stamp">
                                <span class="dry-seal-cross">✠</span>
                                <span class="dry-seal-text-main">OFFICIAL<br>PARISH SEAL</span>
                                <span class="dry-seal-text-sub">DRY SEAL</span>
                            </div>
                            <span class="gold-cross-ornament">✠</span>
                        </div>
                    </td>
                    <td class="footer-sig-col">
                        <div class="signature-box">
                            <div class="signature-name-line"><?php echo htmlspecialchars($priestInCharge); ?></div>
                            <div class="signature-title-label"><?php echo htmlspecialchars($priestInChargeTitle); ?></div>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Security & Control Number -->
            <div class="cert-control-number">
                Official Parish Record &bull; Ref # <?php echo htmlspecialchars($controlNumber); ?>
            </div>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generates a Dompdf instance with configured fonts and Letter paper dimensions.
     */
    public function generateBaptismPdf(array $record, array $options = []): Dompdf
    {
        $missing = $this->validateBaptismRecord($record);
        if (!empty($missing)) {
            throw new InvalidArgumentException('Cannot generate Certificate of Baptism: Missing required fields: ' . implode(', ', $missing));
        }

        $html = $this->renderBaptismCertificateHtml($record, $options);

        $dompdfOptions = new Options();
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('isRemoteEnabled', true);
        $dompdfOptions->set('defaultFont', 'Times-Roman');
        $dompdfOptions->set('dpi', 150);

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf;
    }

    /**
     * Streams the generated PDF directly to the browser for download or inline preview.
     */
    public function streamBaptismPdf(array $record, string $filename = '', bool $download = true): void
    {
        $dompdf = $this->generateBaptismPdf($record);
        if (empty($filename)) {
            $nameSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($record['fullname'] ?? 'parishioner'));
            $filename = 'Certificate_of_Baptism_' . $nameSlug . '.pdf';
        }
        $dompdf->stream($filename, ['Attachment' => $download ? 1 : 0]);
        exit;
    }

    /**
     * Required fields for an official Certificate of Confirmation.
     */
    public static function requiredConfirmationFields(): array
    {
        return [
            'fullname' => 'Confirmand Name',
            'confirmation_date' => 'Date of Confirmation',
            'bishop_priest' => 'Administering Bishop',
            'father_name' => "Father's Name",
            'mother_name' => "Mother's Name",
        ];
    }

    /**
     * Validates whether a Confirmation record has all required fields.
     */
    public function validateConfirmationRecord(array $record): array
    {
        $missing = [];
        $fullname = trim((string)($record['confirmandName'] ?? ($record['fullname'] ?? '')));
        if ($fullname === '' || $fullname === 'N/A') {
            $missing[] = 'Confirmand Name';
        }

        $cdate = trim((string)($record['confirmationDate'] ?? ($record['confirmation_date'] ?? '')));
        if ($cdate === '' || $cdate === '0000-00-00' || $cdate === 'N/A') {
            $missing[] = 'Date of Confirmation';
        }

        $bishop = trim((string)($record['bishopName'] ?? ($record['bishop_priest'] ?? '')));
        if ($bishop === '' || $bishop === 'N/A') {
            $missing[] = 'Administering Bishop';
        }

        $father = trim((string)($record['fatherName'] ?? ($record['father_name'] ?? '')));
        if ($father === '' || $father === 'N/A') {
            $missing[] = "Father's Name";
        }

        $mother = trim((string)($record['motherName'] ?? ($record['mother_name'] ?? '')));
        if ($mother === '' || $mother === 'N/A') {
            $missing[] = "Mother's Name";
        }

        return $missing;
    }

    /**
     * Convert an integer day to an ordinal representation (1ST, 2ND, 3RD, 4TH...).
     */
    public static function formatConfirmationOrdinalDay(int $day): string
    {
        $j = $day % 10;
        $k = $day % 100;
        if ($j === 1 && $k !== 11) return $day . 'ST';
        if ($j === 2 && $k !== 12) return $day . 'ND';
        if ($j === 3 && $k !== 13) return $day . 'RD';
        return $day . 'TH';
    }

    /**
     * Renders the complete, official HTML template for the Certificate of Confirmation.
     * Output is landscape A4 / US Letter, 300 DPI print-ready, with pure SVG Greek-key border and gold seal.
     */
    public function renderConfirmationCertificateHtml(array $record, array $options = []): string
    {
        $root = dirname(__DIR__);
        $crestPath = !empty($record['logoLeftUrl']) && file_exists($record['logoLeftUrl'])
            ? $record['logoLeftUrl']
            : (file_exists($root . '/assets/img/certificates/archdiocese-crest-transparent.png')
                ? $root . '/assets/img/certificates/archdiocese-crest-transparent.png'
                : (file_exists($root . '/assets/img/archdiocese-crest.jfif') ? $root . '/assets/img/archdiocese-crest.jfif' : $root . '/assets/img/archdiocese-crest.jpg'));
        $slrPath = !empty($record['logoRightUrl']) && file_exists($record['logoRightUrl'])
            ? $record['logoRightUrl']
            : (file_exists($root . '/assets/img/certificates/confirmation-medallion-transparent.png')
                ? $root . '/assets/img/certificates/confirmation-medallion-transparent.png'
                : (file_exists($root . '/assets/img/san-lorenzo-logo.png') ? $root . '/assets/img/san-lorenzo-logo.png' : $root . '/assets/img/san-lorenzo-logo.jpg'));
        $borderPath = $root . '/assets/img/certificates/confirmation-greek-border.svg';
        $sealPath = !empty($record['sealUrl']) && file_exists($record['sealUrl'])
            ? $record['sealUrl']
            : $root . '/assets/img/certificates/gold-embossed-parish-seal.svg';

        $crestExt = strtolower(pathinfo($crestPath, PATHINFO_EXTENSION));
        $crestMime = ($crestExt === 'png') ? 'image/png' : (($crestExt === 'svg') ? 'image/svg+xml' : 'image/jpeg');
        $crestUri = self::fileToDataUri($crestPath, $crestMime);

        $slrExt = strtolower(pathinfo($slrPath, PATHINFO_EXTENSION));
        $slrMime = ($slrExt === 'png') ? 'image/png' : (($slrExt === 'svg') ? 'image/svg+xml' : 'image/jpeg');
        $slrUri = self::fileToDataUri($slrPath, $slrMime);

        $borderUri = self::fileToDataUri($borderPath, 'image/svg+xml');
        $sealUri = self::fileToDataUri($sealPath, 'image/svg+xml');

        // Resolve data model fields
        $parishName = strtoupper(trim((string)($record['parishName'] ?? 'SAN LORENZO RUIZ MISSION STATION')));
        $parishLocation = trim((string)($record['parishLocation'] ?? 'Aleosan, Cotabato'));
        $confirmandName = strtoupper(trim((string)($record['confirmandName'] ?? ($record['fullname'] ?? ''))));

        $rawConfDate = trim((string)($record['confirmationDate'] ?? ($record['confirmation_date'] ?? '')));
        $confTs = (!empty($rawConfDate) && $rawConfDate !== '0000-00-00') ? strtotime($rawConfDate) : time();
        $confDayInt = (int)date('j', $confTs);
        $confOrdinalDay = self::formatConfirmationOrdinalDay($confDayInt);
        $confMonthUpper = strtoupper(date('F', $confTs));
        $confYearFull = date('Y', $confTs);
        $confYearCentury = substr($confYearFull, 0, 2);
        $confYearShort = substr($confYearFull, 2, 2);

        $bishopName = trim((string)($record['bishopName'] ?? ($record['bishop_priest'] ?? 'Bp. Angelito R. Lampon, O.M.I., D.D.')));
        $bishopTitle = trim((string)($record['bishopTitle'] ?? 'Archbishop of Cotabato'));

        $fatherName = strtoupper(trim((string)($record['fatherName'] ?? ($record['father_name'] ?? ''))));
        $motherName = strtoupper(trim((string)($record['motherName'] ?? ($record['mother_name'] ?? ''))));

        // Sponsors
        $godfatherName = strtoupper(trim((string)($record['godfatherName'] ?? ($record['godfather'] ?? ''))));
        $godmotherName = strtoupper(trim((string)($record['godmotherName'] ?? ($record['godmother'] ?? ''))));
        if ($godfatherName === '' && !empty($record['sponsor'])) {
            $sponsors = self::parseSponsors($record['sponsor']);
            $godfatherName = strtoupper($sponsors[0] ?? '');
            if ($godmotherName === '' && isset($sponsors[1])) {
                $godmotherName = strtoupper($sponsors[1]);
            }
        }

        $rawIssueDate = trim((string)($record['issueDate'] ?? ($record['date_issued'] ?? ($record['created_at'] ?? ''))));
        $issueTs = (!empty($rawIssueDate) && $rawIssueDate !== '0000-00-00') ? strtotime($rawIssueDate) : time();
        $issueDateFormatted = strtoupper(date('F j, Y', $issueTs));

        $priestName = strtoupper(trim((string)($record['priestName'] ?? ($record['priest_in_charge'] ?? ($record['parish_priest'] ?? 'REV. FR. ALBERTO G. CAHILIG, OMI')))));
        $priestTitle = trim((string)($record['priestTitle'] ?? 'Priest-in-Charge'));

        $certificateNo = trim((string)($record['certificateNo'] ?? ($record['certificate_number'] ?? '')));
        if ($certificateNo === '' && !empty($record['registry_no'])) {
            $certificateNo = 'CONF-' . $confYearFull . '-' . sprintf('%04d', (int)$record['registry_no']);
        }

        $qrUri = '';
        $qrPayload = !empty($record['verificationUrl']) ? $record['verificationUrl'] : (!empty($certificateNo) ? $certificateNo : $confirmandName);
        if (class_exists('\chillerlan\QRCode\QRCode')) {
            try {
                $qrUri = (new \chillerlan\QRCode\QRCode())->render($qrPayload);
            } catch (\Throwable $e) {
                $qrUri = '';
            }
        }

        $paper = strtolower(trim((string)($options['paper'] ?? 'a4')));
        $pageSize = ($paper === 'letter') ? 'letter landscape' : 'a4 landscape';
        $pageWidth = ($paper === 'letter') ? '279.4mm' : '297mm';
        $pageHeight = ($paper === 'letter') ? '215.9mm' : '210mm';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Confirmation - <?php echo htmlspecialchars($confirmandName); ?></title>
    <style>
        @page {
            size: <?php echo $pageSize; ?>;
            margin: 0;
        }
        *, *::before, *::after {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            margin: 0;
            padding: 0;
            background: #F6F0DC;
            color: #111827;
            font-family: 'Times New Roman', Times, Georgia, serif;
            -webkit-font-smoothing: antialiased;
        }
        .cert-container {
            width: <?php echo $pageWidth; ?>;
            height: <?php echo $pageHeight; ?>;
            position: relative;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        .cert-border-svg {
            position: absolute;
            left: 0;
            top: 0;
            width: <?php echo $pageWidth; ?>;
            height: <?php echo $pageHeight; ?>;
            z-index: 1;
        }
        .cert-content {
            position: absolute;
            left: 20mm;
            top: 13mm;
            right: 20mm;
            bottom: 12mm;
            z-index: 10;
            text-align: center;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5mm;
        }
        .header-logo-cell {
            width: 25mm;
            vertical-align: middle;
            text-align: center;
        }
        .header-logo-cell img {
            max-width: 24mm;
            max-height: 24mm;
            display: inline-block;
        }
        .header-title-cell {
            vertical-align: middle;
            text-align: center;
            padding: 0 4mm;
        }
        .cert-title-text {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 25pt;
            font-weight: 700;
            color: #1F5A7A;
            letter-spacing: 0.5px;
            margin: 0 0 1mm 0;
            line-height: 1.1;
        }
        .parish-name-text {
            font-family: 'Times New Roman', Arial, sans-serif;
            font-size: 11pt;
            font-weight: 800;
            color: #1F5A7A;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin: 0 0 0.4mm 0;
        }
        .parish-location-text {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8.5pt;
            color: #555555;
            letter-spacing: 0.3px;
            margin: 0;
        }
        .gold-divider-line {
            width: 82%;
            height: 1px;
            background: #C89B3C;
            margin: 1.8mm auto;
        }
        .recipient-name {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 19pt;
            font-weight: 800;
            color: #111827;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 1.2mm 0 0.8mm;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
        }
        .sacrament-line {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 13pt;
            font-style: italic;
            color: #111827;
            margin: 0.8mm 0 1.2mm;
            line-height: 1.1;
        }
        .canon-date-line {
            font-size: 9pt;
            color: #111827;
            margin-bottom: 1.2mm;
            line-height: 1.3;
        }
        .canon-blank {
            display: inline-block;
            border-bottom: 1.5px solid #1F5A7A;
            color: #111827;
            font-weight: 700;
            padding: 0 4px;
            text-align: center;
        }
        .bishop-lead {
            font-size: 8.2pt;
            color: #4b5563;
            margin-bottom: 0.3mm;
        }
        .bishop-name {
            font-size: 10.8pt;
            font-weight: 800;
            color: #111827;
            margin-bottom: 0.3mm;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
        }
        .bishop-title {
            font-size: 8.2pt;
            color: #374151;
            line-height: 1.1;
        }
        .delegate-confirmed-line {
            font-size: 8.2pt;
            color: #374151;
            margin-top: 0.3mm;
            margin-bottom: 0.6mm;
        }
        .confirmed-name-display {
            font-size: 9.5pt;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            margin-bottom: 0.8mm;
            white-space: nowrap;
            overflow: hidden;
        }
        .parents-sponsors-table {
            width: 78%;
            margin: 0 auto;
            border-collapse: collapse;
        }
        .parent-row-cell {
            padding: 0.6mm 0;
            text-align: center;
        }
        .parent-caption {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.5pt;
            font-style: italic;
            color: #555555;
            line-height: 1;
            margin-bottom: 0.3mm;
        }
        .parent-val {
            font-size: 9.5pt;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.1;
        }
        .parent-rule {
            border-bottom: 1px solid #C89B3C;
            width: 90%;
            margin: 0.4mm auto 0.3mm;
        }
        .cert-statement {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.5pt;
            font-style: italic;
            color: #4b5563;
            margin: 1.5mm auto 1mm;
            text-align: center;
        }
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1mm;
        }
        .footer-side-cell {
            width: 38%;
            vertical-align: bottom;
            text-align: center;
            padding-bottom: 1.5mm;
        }
        .footer-seal-cell {
            width: 24%;
            vertical-align: middle;
            text-align: center;
        }
        .footer-seal-img {
            width: 23mm;
            height: 23mm;
            display: inline-block;
        }
        .footer-val-text {
            font-size: 8.8pt;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.1;
        }
        .footer-rule {
            border-bottom: 1px solid #1F5A7A;
            width: 80%;
            margin: 0.8mm auto 0.5mm;
        }
        .footer-sub-caption {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 7.5pt;
            font-style: italic;
            color: #1F5A7A;
        }
        .qr-wrap {
            text-align: center;
            margin-bottom: 1mm;
        }
        .qr-img {
            width: 14mm;
            height: 14mm;
            display: inline-block;
        }
        .qr-cert-no {
            font-size: 6pt;
            color: #4b5563;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-top: 0.2mm;
        }
    </style>
</head>
<body>
    <div class="cert-container">
        <!-- Pure Vector SVG Greek-Key Meander Frame with Mitered Corners and Gold Line -->
        <img src="<?php echo $borderUri; ?>" class="cert-border-svg" alt="" />

        <!-- Certificate Inner Content -->
        <div class="cert-content">
            <!-- 1. Header Grid -->
            <table class="header-table">
                <tr>
                    <td class="header-logo-cell">
                        <?php if (!empty($crestUri)): ?>
                            <img src="<?php echo $crestUri; ?>" alt="Archdiocese Crest" />
                        <?php endif; ?>
                    </td>
                    <td class="header-title-cell">
                        <div class="cert-title-text">Certificate of Confirmation</div>
                        <div class="parish-name-text"><?php echo htmlspecialchars($parishName); ?></div>
                        <div class="parish-location-text"><?php echo htmlspecialchars($parishLocation); ?></div>
                    </td>
                    <td class="header-logo-cell">
                        <?php if (!empty($slrUri)): ?>
                            <img src="<?php echo $slrUri; ?>" alt="San Lorenzo Ruiz Medallion" />
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div class="gold-divider-line"></div>

            <!-- 2. Recipient Full Name -->
            <div class="recipient-name"><?php echo htmlspecialchars($confirmandName); ?></div>
            <div class="gold-divider-line" style="width: 50%; margin: 0.8mm auto 1.2mm;"></div>

            <!-- 3. Sacrament Declaration -->
            <div class="sacrament-line">received the Holy Sacrament of Confirmation</div>

            <!-- 4. Canonical Details: Day, Month, Year -->
            <div class="canon-date-line">
                in this parish on the <span class="canon-blank" style="min-width: 14mm;"><?php echo htmlspecialchars($confOrdinalDay); ?></span>
                day of <span class="canon-blank" style="min-width: 28mm;"><?php echo htmlspecialchars($confMonthUpper); ?></span>,
                20<span class="canon-blank" style="min-width: 9mm;"><?php echo htmlspecialchars($confYearShort); ?></span>.
            </div>

            <!-- 5. Administered by His Excellency & Bishop Details -->
            <div class="bishop-lead">Administered by His Excellency</div>
            <div class="bishop-name"><?php echo htmlspecialchars($bishopName); ?></div>
            <div class="bishop-title"><?php echo htmlspecialchars($bishopTitle); ?></div>
            <div class="delegate-confirmed-line">or his delegate. Confirmed</div>

            <!-- 6. Confirmed Recipient Repeat Line -->
            <div class="confirmed-name-display"><?php echo htmlspecialchars($confirmandName); ?></div>

            <!-- 7. Four Labeled Lines (Father, Mother, Godfather, Godmother) with Caption directly above on Gold Rule -->
            <table class="parents-sponsors-table">
                <tr>
                    <td class="parent-row-cell">
                        <div class="parent-caption">Father's name</div>
                        <div class="parent-val"><?php echo htmlspecialchars($fatherName); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
                <tr>
                    <td class="parent-row-cell">
                        <div class="parent-caption">Mother's name</div>
                        <div class="parent-val"><?php echo htmlspecialchars($motherName); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
                <tr>
                    <td class="parent-row-cell">
                        <div class="parent-caption">Godfather's name</div>
                        <div class="parent-val"><?php echo htmlspecialchars($godfatherName !== '' ? $godfatherName : 'N/A'); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
                <tr>
                    <td class="parent-row-cell">
                        <div class="parent-caption">Godmother's name</div>
                        <div class="parent-val"><?php echo htmlspecialchars($godmotherName !== '' ? $godmotherName : 'N/A'); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
            </table>

            <!-- 8. Certification Assurance Statement -->
            <div class="cert-statement">
                This is to certify that this certificate is a true copy of Confirmation Record kept in this parish.
            </div>

            <!-- 9. Footer: Issue Date, Gold Embossed Seal, QR Code & Priest Signature Block -->
            <table class="footer-table">
                <tr>
                    <td class="footer-side-cell">
                        <div class="footer-val-text"><?php echo htmlspecialchars($issueDateFormatted); ?></div>
                        <div class="footer-rule"></div>
                        <div class="footer-sub-caption">Date</div>
                    </td>
                    <td class="footer-seal-cell">
                        <img src="<?php echo $sealUri; ?>" class="footer-seal-img" alt="Official Parish Seal" />
                    </td>
                    <td class="footer-side-cell">
                        <?php if (!empty($qrUri)): ?>
                            <div class="qr-wrap">
                                <img src="<?php echo $qrUri; ?>" class="qr-img" alt="QR Code" />
                                <?php if (!empty($certificateNo)): ?>
                                    <div class="qr-cert-no"><?php echo htmlspecialchars($certificateNo); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="footer-val-text"><?php echo htmlspecialchars($priestName); ?></div>
                        <div class="footer-rule"></div>
                        <div class="footer-sub-caption"><?php echo htmlspecialchars($priestTitle); ?></div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generates a Dompdf instance for Confirmation certificate with landscape orientation.
     */
    public function generateConfirmationPdf(array $record, array $options = []): Dompdf
    {
        $missing = $this->validateConfirmationRecord($record);
        if (!empty($missing)) {
            throw new InvalidArgumentException('Cannot generate Certificate of Confirmation: Missing required fields: ' . implode(', ', $missing));
        }

        $html = $this->renderConfirmationCertificateHtml($record, $options);

        $paper = strtolower(trim((string)($options['paper'] ?? 'a4')));
        $paperSize = ($paper === 'letter') ? 'letter' : 'a4';

        $dompdfOptions = new Options();
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('isRemoteEnabled', true);
        $dompdfOptions->set('defaultFont', 'Times-Roman');
        $dompdfOptions->set('dpi', 300);

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paperSize, 'landscape');
        $dompdf->render();

        return $dompdf;
    }

    /**
     * Streams the Confirmation certificate PDF directly to the browser.
     */
    public function streamConfirmationPdf(array $record, string $filename = '', bool $download = true, array $options = []): void
    {
        $dompdf = $this->generateConfirmationPdf($record, $options);
        if (empty($filename)) {
            $nameSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($record['confirmandName'] ?? ($record['fullname'] ?? 'confirmand')));
            $filename = 'Certificate_of_Confirmation_' . $nameSlug . '.pdf';
        }
        $dompdf->stream($filename, ['Attachment' => $download ? 1 : 0]);
        exit;
    }
}

