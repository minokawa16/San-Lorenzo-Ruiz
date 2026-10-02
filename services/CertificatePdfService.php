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

        // Configurable image paths with fallbacks
        $crestPath = !empty($options['logo_left']) && file_exists($options['logo_left'])
            ? $options['logo_left']
            : (!empty($record['logo_left']) && file_exists($record['logo_left'])
                ? $record['logo_left']
                : (file_exists($root . '/assets/img/certificates/archdiocese-crest-transparent.png')
                    ? $root . '/assets/img/certificates/archdiocese-crest-transparent.png'
                    : $root . '/assets/img/archdiocese-crest.jpg'));

        $slrPath = !empty($options['logo_right']) && file_exists($options['logo_right'])
            ? $options['logo_right']
            : (!empty($record['logo_right']) && file_exists($record['logo_right'])
                ? $record['logo_right']
                : (file_exists($root . '/assets/img/certificates/slr_logo.png')
                    ? $root . '/assets/img/certificates/slr_logo.png'
                    : $root . '/assets/img/san-lorenzo-logo.png'));

        $sealPath = !empty($options['seal_image']) && file_exists($options['seal_image'])
            ? $options['seal_image']
            : (!empty($record['seal_image']) && file_exists($record['seal_image'])
                ? $record['seal_image']
                : (file_exists($root . '/assets/img/certificates/gold-embossed-parish-seal.svg')
                    ? $root . '/assets/img/certificates/gold-embossed-parish-seal.svg'
                    : ''));

        // Embedded Base64 Data URIs
        $crestUri = self::fileToDataUri($crestPath);
        $slrUri = self::fileToDataUri($slrPath);
        $sealUri = !empty($sealPath) ? self::fileToDataUri($sealPath) : '';

        // Local Base64 Fonts for zero-CDN offline reliability
        $fontRegular = self::fileToDataUri($root . '/assets/fonts/ebgaramond/EBGaramond-Regular.ttf', 'font/truetype');
        $fontItalic = self::fileToDataUri($root . '/assets/fonts/ebgaramond/EBGaramond-italic.ttf', 'font/truetype');
        $fontBold = self::fileToDataUri($root . '/assets/fonts/ebgaramond/EBGaramond-bold.ttf', 'font/truetype');
        $fontBoldItalic = self::fileToDataUri($root . '/assets/fonts/ebgaramond/EBGaramond-bolditalic.ttf', 'font/truetype');

        // Header strings (configurable)
        $dioceseName = trim((string)($options['diocese_name'] ?? ($record['diocese_name'] ?? 'ARCHDIOCESE OF COTABATO')));
        $parishName = trim((string)($options['parish_name'] ?? ($record['parish_name'] ?? 'SAN LORENZO RUIZ MISSION STATION')));
        $parishLocation = trim((string)($options['parish_location'] ?? ($record['parish_location'] ?? 'Aleosan, Cotabato')));

        // Data values
        $rawFullname = trim((string)($record['fullname'] ?? ''));
        $fullname = mb_strtoupper($rawFullname, 'UTF-8');
        $birthPlace = trim((string)($record['birth_place'] ?? ''));

        // Long date formatting in Asia/Manila
        $tz = new DateTimeZone('Asia/Manila');
        $formatDate = function($dateStr) use ($tz) {
            $str = trim((string)$dateStr);
            if ($str === '' || $str === '0000-00-00' || $str === 'N/A') return '';
            try {
                $dt = new DateTime($str, $tz);
                return $dt->format('F j, Y');
            } catch (\Throwable $e) {
                return $str;
            }
        };

        $birthDate = $formatDate($record['birth_date'] ?? '');
        $residence = trim((string)($record['parent_address'] ?? ($record['residence'] ?? '')));

        $fatherName = trim((string)($record['father_name'] ?? ''));
        $fatherBirthPlace = trim((string)($record['father_birth_place'] ?? ''));

        $motherName = trim((string)($record['mother_name'] ?? ''));
        $motherBirthPlace = trim((string)($record['mother_birth_place'] ?? ''));

        $baptismDate = $formatDate($record['baptism_date'] ?? '');

        $rawPriest = trim((string)($record['priest'] ?? ($record['officiating_priest'] ?? '')));
        $priestClean = preg_replace('/^(?:by\s+the\s+)?(?:rev\.?\s*fr\.?\s*|father\s*|fr\.?\s*)/i', '', $rawPriest);
        $officiatingMinister = mb_strtoupper(trim($priestClean), 'UTF-8');

        // Sponsors parsing
        $sponsorsRaw = $record['godparents'] ?? ($record['sponsors'] ?? ($record['sponsor'] ?? ''));
        $sponsors = self::parseSponsors($sponsorsRaw);
        if (empty($sponsors)) {
            $sponsors = ['']; // at least one empty underlined line
        } elseif (count($sponsors) > 6) {
            $sponsors = array_slice($sponsors, 0, 6);
        }

        // Priest-in-charge
        $priestInCharge = !empty($record['priest_in_charge']) ? trim((string)$record['priest_in_charge']) : $this->getPriestInChargeName();
        $priestInCharge = mb_strtoupper($priestInCharge, 'UTF-8');
        $priestTitle = !empty($record['priest_title']) ? trim((string)$record['priest_title']) : (!empty($record['signatory_title']) ? trim((string)$record['signatory_title']) : $this->getPriestInChargeTitle());

        // Sponsor count styling adaptation
        $sponsorCount = count($sponsors);
        $groupGap = ($sponsorCount > 4) ? '3mm' : '4mm';
        $rowGap = ($sponsorCount > 4) ? '5.2mm' : '6.2mm';
        $valFontSize = ($sponsorCount > 4) ? '11pt' : '11.5pt';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Certificate of Baptism - <?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        @page {
            size: 210mm 297mm;
            margin: 0;
        }
        @font-face {
            font-family: 'EBGaramond';
            src: url('<?php echo $fontRegular; ?>') format('truetype');
            font-weight: 400;
            font-style: normal;
        }
        @font-face {
            font-family: 'EBGaramond';
            src: url('<?php echo $fontItalic; ?>') format('truetype');
            font-weight: 400;
            font-style: italic;
        }
        @font-face {
            font-family: 'EBGaramond';
            src: url('<?php echo $fontBold; ?>') format('truetype');
            font-weight: 700;
            font-style: normal;
        }
        @font-face {
            font-family: 'EBGaramond';
            src: url('<?php echo $fontBoldItalic; ?>') format('truetype');
            font-weight: 700;
            font-style: italic;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        html, body {
            margin: 0;
            padding: 0;
            width: 210mm;
            height: 297mm;
            background-color: #FBF7EC;
            color: #222222;
            font-family: 'EBGaramond', 'Times New Roman', Georgia, serif;
            -webkit-font-smoothing: antialiased;
        }
        .cert-page {
            width: 210mm;
            height: 297mm;
            position: relative;
            background-color: #FBF7EC;
            overflow: hidden;
            box-sizing: border-box;
        }

        /* ── VECTOR SVG BORDER (Outer Navy 0.8mm, Inner Gold 0.25mm) ── */
        .cert-border-svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 210mm;
            height: 297mm;
            pointer-events: none;
            z-index: 1;
        }

        /* ── INNER CONTENT CONTAINER (Stays >= 12mm inside gold line => >= 25.5mm from page edge) ── */
        .cert-inner {
            position: absolute;
            top: 25.5mm;
            left: 25.5mm;
            width: 159mm;
            height: 246mm;
            z-index: 10;
            box-sizing: border-box;
        }

        /* ── HEADER TABLE ── */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .header-logo-left {
            width: 24mm;
            height: 24mm;
            vertical-align: middle;
            text-align: left;
            padding: 0;
        }
        .header-logo-right {
            width: 24mm;
            height: 24mm;
            vertical-align: middle;
            text-align: right;
            padding: 0;
        }
        .header-logo-img {
            width: 24mm;
            height: 24mm;
            max-width: 24mm;
            max-height: 24mm;
            object-fit: contain;
            display: inline-block;
            vertical-align: middle;
            background: transparent;
        }
        .header-center-text {
            vertical-align: middle;
            text-align: center;
            padding: 0 2mm;
        }
        .header-diocese {
            font-size: 10pt;
            letter-spacing: 2.2px;
            color: #1F3A5F;
            text-transform: uppercase;
            margin: 0 0 1.2mm 0;
            line-height: 1.15;
            font-weight: 500;
        }
        .header-parish {
            font-size: 12pt;
            font-weight: 700;
            color: #1F3A5F;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            margin: 0 0 1.2mm 0;
            line-height: 1.15;
        }
        .header-location {
            font-size: 10pt;
            font-style: italic;
            color: #6B6B6B;
            margin: 0;
            line-height: 1.15;
        }

        /* ── GOLD DIVIDER WITH CENTER DIAMOND (Open diamond, 60% inner width ~95mm) ── */
        .header-divider-wrap {
            text-align: center;
            margin: 3.5mm auto 2.5mm auto;
            width: 95mm;
            height: 6px;
        }
        .header-divider-svg {
            display: block;
            margin: 0 auto;
            width: 95mm;
            height: 6px;
        }

        /* ── TITLE BLOCK ── */
        .title-block {
            text-align: center;
            margin-top: 1mm;
            margin-bottom: 4mm;
        }
        .title-main {
            font-size: 30pt;
            color: #1F3A5F;
            letter-spacing: 1.5px;
            line-height: 1.1;
            margin: 0;
            font-weight: 400;
        }
        .title-sub {
            font-size: 10.5pt;
            font-style: italic;
            color: #6B6B6B;
            margin-top: 1.2mm;
            margin-bottom: 0;
            line-height: 1.2;
            letter-spacing: 0.3px;
        }

        /* ── FIELDS AREA ── */
        .fields-container {
            width: 100%;
            margin-top: 2mm;
            box-sizing: border-box;
        }
        .field-group {
            margin-bottom: <?php echo $groupGap; ?>;
            width: 100%;
        }
        .field-group:last-child {
            margin-bottom: 0;
        }
        .field-row-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: <?php echo $rowGap; ?>;
        }
        .field-row-table:last-child {
            margin-bottom: 0;
        }
        .field-label-cell {
            width: 38mm;
            vertical-align: bottom;
            text-align: left;
            padding: 0 2mm 1.5px 0;
            font-size: 10.5pt;
            font-style: italic;
            color: #8A6D2B;
            white-space: nowrap;
            line-height: 1.2;
        }
        .field-value-cell {
            vertical-align: bottom;
            text-align: left;
            padding: 0 0 1.5px 1.5mm;
            border-bottom: 0.5px solid #D8CBA6;
            line-height: 1.2;
        }
        .field-value-text {
            font-size: <?php echo $valFontSize; ?>;
            color: #222222;
            white-space: nowrap;
            overflow: hidden;
            display: block;
            line-height: 1.2;
        }
        .field-value-text.name-value {
            font-size: 13pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #222222;
        }
        .field-value-text.minister-value {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #222222;
        }

        /* ── FOOTER AREA ── */
        .cert-footer-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            position: absolute;
            bottom: 0;
            left: 0;
        }
        .footer-seal-cell {
            width: 28mm;
            height: 28mm;
            vertical-align: middle;
            text-align: left;
            padding: 0;
        }
        .parish-seal-img {
            width: 28mm;
            height: 28mm;
            max-width: 28mm;
            max-height: 28mm;
            object-fit: contain;
            display: inline-block;
            background: transparent;
        }
        .footer-middle-cell {
            vertical-align: bottom;
            padding: 0;
        }
        .footer-sig-cell {
            width: 66mm;
            vertical-align: bottom;
            text-align: center;
            padding: 0;
        }
        .sig-line {
            width: 62mm;
            margin: 0 auto;
            border-bottom: 1px solid #1F3A5F;
            padding-bottom: 1.5mm;
            min-height: 5mm;
        }
        .sig-name {
            font-size: 10.5pt;
            font-weight: 700;
            color: #222222;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            line-height: 1.15;
            white-space: nowrap;
        }
        .sig-title {
            font-size: 10pt;
            font-style: italic;
            color: #6B6B6B;
            margin-top: 1.2mm;
            line-height: 1.15;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="cert-page">
        <!-- Pure SVG Vector Border (Square corners, no ornaments) -->
        <svg class="cert-border-svg" viewBox="0 0 210 297" width="210mm" height="297mm">
            <!-- Outer Navy Frame: 0.8mm thick, inset 10mm from page edge -->
            <rect x="10" y="10" width="190" height="277" fill="none" stroke="#1F3A5F" stroke-width="0.8" />
            <!-- Inner Thin Gold Line: 0.25mm thick, 3.5mm inside navy line (13.5mm from page edge) -->
            <rect x="13.5" y="13.5" width="183" height="270" fill="none" stroke="#B8923A" stroke-width="0.25" />
        </svg>

        <div class="cert-inner">
            <!-- ── HEADER ── -->
            <table class="header-table">
                <tr>
                    <td class="header-logo-left">
                        <?php if (!empty($crestUri)): ?>
                            <img class="header-logo-img" src="<?php echo $crestUri; ?>" alt="Archdiocese Coat of Arms">
                        <?php endif; ?>
                    </td>
                    <td class="header-center-text">
                        <div class="header-diocese"><?php echo htmlspecialchars($dioceseName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="header-parish"><?php echo htmlspecialchars($parishName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="header-location"><?php echo htmlspecialchars($parishLocation, ENT_QUOTES, 'UTF-8'); ?></div>
                    </td>
                    <td class="header-logo-right">
                        <?php if (!empty($slrUri)): ?>
                            <img class="header-logo-img" src="<?php echo $slrUri; ?>" alt="San Lorenzo Ruiz Logo">
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <!-- ── GOLD DIVIDER WITH CENTER OPEN DIAMOND (~60% inner width = 95mm) ── -->
            <div class="header-divider-wrap">
                <svg class="header-divider-svg" viewBox="0 0 100 6">
                    <line x1="0" y1="3" x2="46" y2="3" stroke="#B8923A" stroke-width="0.6"/>
                    <polygon points="50,0.5 53.5,3 50,5.5 46.5,3" fill="none" stroke="#B8923A" stroke-width="0.6"/>
                    <line x1="53.5" y1="3" x2="100" y2="3" stroke="#B8923A" stroke-width="0.6"/>
                </svg>
            </div>

            <!-- ── TITLE BLOCK ── -->
            <div class="title-block">
                <h1 class="title-main">Certificate of Baptism</h1>
                <div class="title-sub">Issued from the Official Parish Records</div>
            </div>

            <!-- ── TWO-COLUMN FIELDS AREA ── -->
            <div class="fields-container">
                <!-- Group 1: Name, Birthplace, Birthday, Residence -->
                <div class="field-group">
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Name:</td>
                            <td class="field-value-cell"><span class="field-value-text name-value"><?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Birthplace:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($birthPlace, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Birthday:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($birthDate, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Residence:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($residence, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                </div>

                <!-- Group 2: Father, Birthplace -->
                <div class="field-group">
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Father:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($fatherName, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Birthplace:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($fatherBirthPlace, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                </div>

                <!-- Group 3: Mother, Birthplace -->
                <div class="field-group">
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Mother:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($motherName, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Birthplace:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($motherBirthPlace, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                </div>

                <!-- Group 4: Date of Baptism, By the Rev. Fr. -->
                <div class="field-group">
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">Date of Baptism:</td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars($baptismDate, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell">By the Rev. Fr.</td>
                            <td class="field-value-cell"><span class="field-value-text minister-value"><?php echo htmlspecialchars($officiatingMinister, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                </div>

                <!-- Group 5: Sponsors (Dynamic List 1-6 lines, aligned) -->
                <div class="field-group">
                    <?php foreach ($sponsors as $idx => $sponsor): ?>
                    <table class="field-row-table">
                        <tr>
                            <td class="field-label-cell"><?php echo ($idx === 0) ? 'Sponsors:' : ''; ?></td>
                            <td class="field-value-cell"><span class="field-value-text"><?php echo htmlspecialchars(trim((string)$sponsor), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    </table>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── FOOTER: SEAL (28mm) AND SIGNATURE (62mm) ── -->
            <table class="cert-footer-table">
                <tr>
                    <td class="footer-seal-cell">
                        <?php if (!empty($sealUri)): ?>
                            <img class="parish-seal-img" src="<?php echo $sealUri; ?>" alt="Parish Seal">
                        <?php endif; ?>
                    </td>
                    <td class="footer-middle-cell"></td>
                    <td class="footer-sig-cell">
                        <div class="sig-line">
                            <div class="sig-name"><?php echo htmlspecialchars($priestInCharge, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="sig-title"><?php echo htmlspecialchars($priestTitle, ENT_QUOTES, 'UTF-8'); ?></div>
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
     * Generates a Dompdf instance with configured fonts and A4 portrait dimensions.
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
        $dompdfOptions->set('defaultFont', 'EBGaramond');
        $dompdfOptions->set('dpi', 300);

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
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

    public static function fitFontSize(string $text, int $maxPt, int $minPt, int $maxChars): string
    {
        $len = mb_strlen(trim($text));
        if ($len <= $maxChars) {
            return $maxPt . 'pt';
        }
        $ratio = max(0.5, $maxChars / $len);
        $size = max($minPt, (int)round($maxPt * $ratio));
        return $size . 'pt';
    }

    /**
     * Renders the complete, official HTML template for the Certificate of Confirmation.
     * Output is landscape A4 / US Letter, 300 DPI print-ready, with pure SVG Greek-key border.
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
            : (file_exists($root . '/assets/img/certificates/slr_logo.png')
                ? $root . '/assets/img/certificates/slr_logo.png'
                : (file_exists($root . '/assets/img/certificates/confirmation-medallion-transparent.png')
                    ? $root . '/assets/img/certificates/confirmation-medallion-transparent.png'
                    : (file_exists($root . '/assets/img/san-lorenzo-logo.png') ? $root . '/assets/img/san-lorenzo-logo.png' : $root . '/assets/img/san-lorenzo-logo.jpg')));
        $borderPath = $root . '/assets/img/certificates/confirmation-greek-border.svg';

        $crestExt = strtolower(pathinfo($crestPath, PATHINFO_EXTENSION));
        $crestMime = ($crestExt === 'png') ? 'image/png' : (($crestExt === 'svg') ? 'image/svg+xml' : 'image/jpeg');
        $crestUri = self::fileToDataUri($crestPath, $crestMime);

        $slrExt = strtolower(pathinfo($slrPath, PATHINFO_EXTENSION));
        $slrMime = ($slrExt === 'png') ? 'image/png' : (($slrExt === 'svg') ? 'image/svg+xml' : 'image/jpeg');
        $slrUri = self::fileToDataUri($slrPath, $slrMime);

        $borderUri = self::fileToDataUri($borderPath, 'image/svg+xml');

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

        $confirmandFontSize = self::fitFontSize($confirmandName, 28, 16, 26);
        $bishopFontSize = self::fitFontSize($bishopName, 14, 10, 35);
        $confirmedRepeatFontSize = self::fitFontSize($confirmandName, 15, 11, 30);
        $fatherFontSize = self::fitFontSize($fatherName, 13, 9, 28);
        $motherFontSize = self::fitFontSize($motherName, 13, 9, 28);
        $godfatherFontSize = self::fitFontSize($godfatherName, 13, 9, 28);
        $godmotherFontSize = self::fitFontSize($godmotherName, 13, 9, 28);
        $priestFontSize = self::fitFontSize($priestName, 12.5, 9.5, 30);

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
            left: 24mm;
            top: 22mm;
            right: 24mm;
            bottom: 28mm;
            height: 160mm;
            box-sizing: border-box;
            z-index: 10;
            text-align: center;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0.8mm;
        }
        .header-logo-cell {
            width: 25mm;
            height: 25mm;
            vertical-align: middle;
            text-align: center;
        }
        .header-logo-cell img {
            height: 25mm;
            max-width: 25mm;
            object-fit: contain;
            display: inline-block;
        }
        .header-title-cell {
            vertical-align: middle;
            text-align: center;
            padding: 0 4mm;
        }
        .cert-title-text {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 33pt;
            font-weight: 700;
            color: #1F5A7A;
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1.05;
        }
        .parish-name-text {
            font-family: 'Times New Roman', Arial, sans-serif;
            font-size: 14pt;
            font-weight: 700;
            font-variant: small-caps;
            color: #1F5A7A;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 0.8mm 0 0.3mm 0;
            line-height: 1.1;
        }
        .parish-location-text {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 10.5pt;
            color: #555555;
            letter-spacing: 0.3px;
            margin: 0;
            line-height: 1.05;
        }
        .recipient-name {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: <?php echo $confirmandFontSize; ?>;
            font-weight: 800;
            color: #111827;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin: 1.2mm 0 0 0;
            line-height: 1.05;
            white-space: nowrap;
            overflow: hidden;
        }
        .recipient-rule {
            width: 55%;
            height: 0;
            border-bottom: 0.75pt solid #C89B3C;
            margin: 1.8mm auto 1.2mm auto;
        }
        .sacrament-line {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 16.5pt;
            font-style: italic;
            color: #111827;
            margin: 0.8mm 0 1mm 0;
            line-height: 1.1;
        }
        .canon-date-line {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 12pt;
            color: #111827;
            margin: 0.8mm 0;
            line-height: 1.2;
        }
        .canon-blank {
            display: inline-block;
            border-bottom: 0.75pt solid #C89B3C;
            color: #111827;
            font-weight: 700;
            padding: 0 4px;
            text-align: center;
        }
        .bishop-lead {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 10.5pt;
            color: #4b5563;
            margin-bottom: 0.2mm;
            line-height: 1.15;
        }
        .bishop-name {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: <?php echo $bishopFontSize; ?>;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.2mm;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
        }
        .bishop-title {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 10.5pt;
            color: #374151;
            line-height: 1.15;
            margin-bottom: 0.2mm;
        }
        .delegate-confirmed-line {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 10.5pt;
            color: #374151;
            line-height: 1.15;
            margin-bottom: 0.4mm;
        }
        .confirmed-name-display {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: <?php echo $confirmedRepeatFontSize; ?>;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.8mm;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.15;
        }
        .parents-sponsors-table {
            width: 60%;
            margin: 1.2mm auto;
            border-collapse: collapse;
        }
        .parent-row-cell {
            padding: 0.4mm 0;
            text-align: center;
        }
        .parent-caption {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 8.5pt;
            font-style: italic;
            color: #555555;
            line-height: 1.1;
            margin-bottom: 0.3mm;
        }
        .parent-val {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 13pt;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.15;
            min-height: 4.5mm;
        }
        .parent-rule {
            border-bottom: 0.75pt solid #C89B3C;
            width: 100%;
            margin: 1.2mm auto 0.4mm auto;
        }
        .cert-statement {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 9pt;
            font-style: italic;
            color: #4b5563;
            margin: 1mm auto 0 auto;
            text-align: center;
            line-height: 1.15;
        }
        .footer-table {
            width: 32%;
            margin: 0 auto;
            border-collapse: collapse;
            position: absolute;
            bottom: 0;
            left: 34%;
            right: 34%;
            text-align: center;
        }
        .footer-val-text {
            font-size: <?php echo $priestFontSize; ?>;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.2;
        }
        .footer-rule {
            border-bottom: 0.75pt solid #C89B3C;
            width: 100%;
            margin: 1.5mm auto 1mm auto;
        }
        .footer-sub-caption {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 9.5pt;
            font-style: italic;
            color: #1F5A7A;
            line-height: 1.2;
        }
        .qr-wrap {
            text-align: center;
            margin-bottom: 0.8mm;
        }
        .qr-img {
            width: 10mm;
            height: 10mm;
            display: inline-block;
        }
        .qr-cert-no {
            font-size: 5.5pt;
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
                            <img src="<?php echo $slrUri; ?>" alt="San Lorenzo Ruiz Logo" />
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <!-- Header Divider: 70% width, softly fading ends -->
            <div style="text-align: center; margin: 1mm auto 1.2mm auto;">
                <svg width="70%" height="2" viewBox="0 0 700 2" style="display: block; margin: 0 auto;" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="goldFadeGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#C89B3C" stop-opacity="0" />
                            <stop offset="15%" stop-color="#C89B3C" stop-opacity="1" />
                            <stop offset="85%" stop-color="#C89B3C" stop-opacity="1" />
                            <stop offset="100%" stop-color="#C89B3C" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <rect x="0" y="0" width="700" height="1.5" fill="url(#goldFadeGrad)" />
                </svg>
            </div>

            <!-- 2. Recipient Full Name -->
            <div class="recipient-name"><?php echo htmlspecialchars($confirmandName); ?></div>
            <div class="recipient-rule"></div>

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
                        <div class="parent-val" style="font-size: <?php echo $fatherFontSize; ?>;"><?php echo htmlspecialchars($fatherName !== '' ? $fatherName : 'N/A'); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
                <tr>
                    <td class="parent-row-cell">
                        <div class="parent-caption">Mother's name</div>
                        <div class="parent-val" style="font-size: <?php echo $motherFontSize; ?>;"><?php echo htmlspecialchars($motherName !== '' ? $motherName : 'N/A'); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
                <tr>
                    <td class="parent-row-cell">
                        <div class="parent-caption">Godfather's name</div>
                        <div class="parent-val" style="font-size: <?php echo $godfatherFontSize; ?>;"><?php echo htmlspecialchars($godfatherName !== '' ? $godfatherName : 'N/A'); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
                <tr>
                    <td class="parent-row-cell">
                        <div class="parent-caption">Godmother's name</div>
                        <div class="parent-val" style="font-size: <?php echo $godmotherFontSize; ?>;"><?php echo htmlspecialchars($godmotherName !== '' ? $godmotherName : 'N/A'); ?></div>
                        <div class="parent-rule"></div>
                    </td>
                </tr>
            </table>

            <!-- 8. Certification Assurance Statement -->
            <div class="cert-statement">
                This is to certify that this certificate is a true copy of Confirmation Record kept in this parish.
            </div>

            <!-- 9. Centered Priest Signature Block (Issue date removed) -->
            <table class="footer-table">
                <tr>
                    <td style="text-align: center; vertical-align: bottom; padding: 0;">
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

