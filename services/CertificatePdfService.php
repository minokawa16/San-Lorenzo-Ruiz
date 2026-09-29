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
        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('parish.priest_in_charge', 'parish_priest_name') AND setting_value IS NOT NULL AND setting_value != '' ORDER BY CASE WHEN setting_key = 'parish.priest_in_charge' THEN 1 ELSE 2 END LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty(trim((string)$row['setting_value']))) {
                return trim((string)$row['setting_value']);
            }
        }
        return 'REV. FR. HERIBERTO C. VILLAS, O.M.I.';
    }

    /**
     * Retrieve the Priest-in-Charge Title configured in Parish Settings.
     */
    public function getPriestInChargeTitle(): string
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'parish.priest_in_charge_title' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty(trim((string)$row['setting_value']))) {
                return trim((string)$row['setting_value']);
            }
        }
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
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
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
            display: flex;
            flex-direction: column;
            justify-content: space-between;
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

        /* ── BODY FIELDS (Continuous Underline Form) ── */
        .cert-body-form {
            width: 100%;
            margin-top: 14px;
            margin-bottom: auto;
        }
        .cert-field-row {
            display: flex;
            align-items: flex-end;
            width: 100%;
            margin-bottom: 7.5px;
            line-height: 1.15;
        }
        .cert-field-row.indent {
            padding-left: 36px;
        }
        .cert-field-row.sponsor-extra {
            padding-left: 104px;
            margin-top: -2px;
        }
        .field-label {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 11.8pt;
            font-weight: 700;
            font-style: italic;
            color: #561212;
            white-space: nowrap;
            margin-right: 6px;
            flex-shrink: 0;
        }
        .field-fill-line {
            flex-grow: 1;
            border-bottom: 1.2px solid #561212;
            padding-left: 6px;
            padding-bottom: 1px;
            min-height: 20px;
        }
        .field-value {
            font-family: 'Courier New', Courier, monospace, serif;
            font-size: 12pt;
            font-weight: 700;
            color: #111111;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .field-value.name-value {
            font-size: 13.5pt;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        /* Compact styling if many sponsors */
        .compact-sponsors .cert-field-row {
            margin-bottom: 4.5px;
        }
        .compact-sponsors .field-label {
            font-size: 10.5pt;
        }
        .compact-sponsors .field-value {
            font-size: 10.5pt;
        }

        .cert-purpose-text {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 10pt;
            font-style: italic;
            color: #561212;
            text-align: center;
            margin-top: 6px;
        }
        .cert-purpose-text span {
            font-family: 'Courier New', monospace;
            font-style: normal;
            font-weight: 700;
            border-bottom: 1px solid #561212;
            padding: 0 4px;
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
            display: inline-flex;
            align-items: center;
            gap: 16px;
        }
        .dry-seal-stamp {
            width: 82px;
            height: 82px;
            border: 1.5px dashed #7a2323;
            border-radius: 50%;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #7a2323;
            padding: 4px;
            box-shadow: inset 0 0 0 2px rgba(122, 35, 35, 0.15);
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
}
