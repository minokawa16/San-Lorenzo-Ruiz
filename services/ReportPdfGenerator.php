<?php
/**
 * ReportPdfGenerator
 * ------------------
 * Institutional PDF Report Generator for San Lorenzo Ruiz Mission Station.
 * Produces standardized, formal parish reports with the canonical Archdiocese of Cotabato
 * letterhead, gold cross divider, normalized table data, and multi-page footer.
 */

declare(strict_types=1);

$vendorAutoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Standalone / reusable helper function to generate standardized Parish Report Header HTML.
 *
 * @param string            $reportTitle e.g. 'Turnaround Report'
 * @param string|null       $subtitle    e.g. 'Request Turnaround'
 * @param array|string|null $filters     Active filters array or formatted string
 * @param array             $meta        Optional metadata (e.g. ['generated_by' => '...', 'date' => '...'])
 * @return string HTML string for the parish report header
 */
function generateParishReportHeader(
    string $reportTitle,
    ?string $subtitle = null,
    array|string|null $filters = null,
    array $meta = []
): string {
    return ReportPdfGenerator::generateParishReportHeader($reportTitle, $subtitle, $filters, $meta);
}

final class ReportPdfGenerator
{
    /**
     * Generate and stream/download a PDF report.
     *
     * @param string      $reportKey   e.g. 'turnaround', 'pending_overdue', 'audit_log'
     * @param string      $title       e.g. 'Turnaround Report'
     * @param array       $filters     e.g. ['from' => '2026-08-01', 'to' => '...', 'status' => '...', 'type' => '...']
     * @param array       $data        e.g. ['columns' => [...], 'rows' => [...], 'total' => 12, 'truncated' => false]
     * @param string      $generatedBy Name of admin/staff generating report
     * @param string      $orientation 'landscape' or 'portrait' (default 'portrait')
     * @param string|null $subtitle    e.g. 'Request Turnaround'
     */
    public static function stream(
        string $reportKey,
        string $title,
        array $filters,
        array $data,
        string $generatedBy = 'Administrator',
        string $orientation = 'portrait',
        ?string $subtitle = null,
        array $charts = [],
        array $meta = []
    ): void {
        $html = self::buildHtml($reportKey, $title, $filters, $data, $generatedBy, $subtitle, $charts, $meta, true);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        // Multi-page footer text via Dompdf Canvas
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('Helvetica', 'normal');
        $size = 7.5;
        $color = [0.45, 0.45, 0.45]; // Muted gray

        $width = $canvas->get_width();
        $height = $canvas->get_height();

        // Footer left: Confidentiality note
        $canvas->page_text(
            35,
            $height - 22,
            "CONFIDENTIAL  —  San Lorenzo Ruiz Mission Station Official Document",
            $font,
            $size,
            $color
        );

        // Footer right: Page X of Y
        $canvas->page_text(
            $width - 95,
            $height - 22,
            "Page {PAGE_NUM} of {PAGE_COUNT}",
            $font,
            $size,
            $color
        );

        $filename = 'SLR-Report-' . preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower($reportKey)) . '-' . date('Ymd-His') . '.pdf';
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    /**
     * Reusable component to render the canonical Parish Report Header HTML.
     */
    public static function generateParishReportHeader(
        string $reportTitle,
        ?string $subtitle = null,
        array|string|null $filters = null,
        array $meta = []
    ): string {
        $logoBase64 = self::getLogoBase64();

        // Format active filters string
        $filtersFormatted = '[]';
        if (is_array($filters)) {
            $active = [];
            if (!empty($filters['from']) || !empty($filters['to'])) {
                $fromStr = !empty($filters['from']) ? date('M d, Y', strtotime($filters['from'])) : 'Beginning';
                $toStr = !empty($filters['to']) ? date('M d, Y', strtotime($filters['to'])) : 'Present';
                $active[] = "Date: $fromStr to $toStr";
            }
            if (!empty($filters['status'])) {
                $active[] = 'Status: ' . self::humanize((string)$filters['status']);
            }
            if (!empty($filters['type'])) {
                $active[] = 'Type: ' . self::humanize((string)$filters['type']);
            }
            if (!empty($filters['q'])) {
                $active[] = 'Search: "' . htmlspecialchars((string)$filters['q'], ENT_QUOTES, 'UTF-8') . '"';
            }
            if (!empty($filters['component'])) {
                $active[] = 'Component: ' . self::humanize((string)$filters['component']);
            }
            if (!empty($active)) {
                $filtersFormatted = '[' . implode(' | ', $active) . ']';
            }
        } elseif (is_string($filters) && $filters !== '') {
            $filtersFormatted = $filters;
        }

        $timestamp = !empty($meta['timestamp']) ? (string)$meta['timestamp'] : date('F d, Y \a\t g:i A');

        ob_start();
        ?>
        <table class="parish-letterhead" cellpadding="0" cellspacing="0">
            <tr>
                <td class="letterhead-logo-cell">
                    <?php if ($logoBase64): ?>
                        <img src="<?php echo $logoBase64; ?>" alt="San Lorenzo Ruiz Mission Station" class="parish-logo-img">
                    <?php else: ?>
                        <div class="parish-logo-placeholder"></div>
                    <?php endif; ?>
                </td>
                <td class="letterhead-text-cell">
                    <div class="diocese-name">ARCHDIOCESE OF COTABATO</div>
                    <div class="parish-name">SAN LORENZO RUIZ MISSION STATION</div>
                    <div class="parish-location">Aleosan, North Cotabato</div>
                </td>
                <td class="letterhead-balance-cell">
                    <!-- Symmetrical spacer cell for true document center alignment -->
                </td>
            </tr>
        </table>

        <table class="gold-cross-divider" cellpadding="0" cellspacing="0">
            <tr>
                <td class="divider-line"></td>
                <td class="divider-cross">&#8224;</td>
                <td class="divider-line"></td>
            </tr>
        </table>

        <div class="report-header-section">
            <div class="report-title"><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if (!empty($subtitle)): ?>
                <div class="report-subtitle"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div class="report-meta-line">
                <span class="report-timestamp">Generated on <?php echo htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (!empty($filtersFormatted) && $filtersFormatted !== '[]'): ?>
                    <span class="report-filters">&nbsp;|&nbsp; Filters: <?php echo htmlspecialchars($filtersFormatted, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build the complete standalone HTML string for continuous-flow PDF and Print.
     */
    public static function buildHtml(
        string $reportKey,
        string $title,
        array $filters,
        array $data,
        string $generatedBy = 'Administrator',
        ?string $subtitle = null,
        array $charts = [],
        array $meta = [],
        bool $isDompdf = false
    ): string {
        $columns = $data['columns'] ?? [];
        $rows = $data['rows'] ?? [];
        $totalRecords = (int) ($data['total'] ?? count($rows));
        $truncated = !empty($data['truncated']);

        $headerHtml = self::generateParishReportHeader($title, $subtitle, $filters, $meta);

        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
    @page {
        size: portrait;
        margin: 10mm 12mm 12mm 12mm;
    }
    *, *::before, *::after {
        box-sizing: border-box;
    }
    body {
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 8pt;
        line-height: 1.3;
        color: #1e293b;
        background: #ffffff;
        margin: 0;
        padding: 0;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    @media screen {
        body {
            padding: 20px 24px;
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
        }
    }
    @media print {
        body {
            padding: 0 !important;
            margin: 0 !important;
            max-width: none !important;
        }
    }

    /* Prevent awkward cuts inside individual blocks without forcing blank page breaks */
    .chart-box, .kpi-summary-table, .chart-two-col, table.data-table tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    /* ── Formal Parish Letterhead ────────────────────────────────── */
    .parish-letterhead {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2px;
    }
    .parish-letterhead td {
        vertical-align: middle;
        padding: 0;
    }
    .letterhead-logo-cell {
        width: 75px;
        text-align: left;
    }
    .parish-logo-img {
        height: 60px;
        width: auto;
        display: block;
    }
    .parish-logo-placeholder {
        width: 58px;
        height: 58px;
        border-radius: 50%;
        background: #1e2d24;
        display: block;
    }
    .letterhead-text-cell {
        text-align: center;
        padding: 0 8px;
    }
    .letterhead-balance-cell {
        width: 75px;
    }
    .diocese-name {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 10pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #1e293b;
        margin-bottom: 2px;
        line-height: 1.15;
    }
    .parish-name {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 14pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #0f172a;
        margin-bottom: 2px;
        line-height: 1.15;
    }
    .parish-location {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 9pt;
        color: #475569;
        line-height: 1.15;
    }

    /* ── Gold Divider with Centered Latin Cross ───────────────────── */
    .gold-cross-divider {
        width: 100%;
        border-collapse: collapse;
        margin-top: 4px;
        margin-bottom: 8px;
    }
    .gold-cross-divider td {
        padding: 0;
        vertical-align: middle;
    }
    .divider-line {
        border-bottom: 1.5px solid #c89b3c;
        width: 48%;
    }
    .divider-cross {
        width: 4%;
        text-align: center;
        color: #c89b3c;
        font-size: 13pt;
        font-weight: bold;
        line-height: 1;
        padding: 0 4px;
        font-family: 'Times New Roman', serif;
    }

    /* ── Report Title & Filters ──────────────────────────────────── */
    .report-header-section {
        margin-bottom: 8px;
        text-align: left;
    }
    .report-title {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 13pt;
        font-weight: bold;
        color: #0f172a;
        margin-bottom: 2px;
        line-height: 1.2;
    }
    .report-subtitle {
        font-size: 8.8pt;
        color: #475569;
        font-weight: 500;
        margin-bottom: 2px;
    }
    .report-meta-line {
        font-size: 7.5pt;
        color: #64748b;
        line-height: 1.3;
    }

    /* ── KPI Overview Summary (Compact single-line flow) ────────── */
    .kpi-summary-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 4px;
        margin-bottom: 8px;
    }
    .kpi-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 6px 8px;
        text-align: center;
    }
    .kpi-num {
        font-size: 12.5pt;
        font-weight: bold;
        color: #1e3a5f;
        line-height: 1.15;
    }
    .kpi-lbl {
        font-size: 6.2pt;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        font-weight: 700;
        margin-top: 2px;
    }

    /* ── Operational Summary Section (Compact, Institutional Aesthetic) ── */
    .pdf-op-section {
        margin-top: 4px;
        margin-bottom: 7px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .pdf-op-header {
        margin-bottom: 4px;
        text-align: left;
    }
    .pdf-op-title {
        font-family: 'Playfair Display', 'Times New Roman', 'Georgia', serif;
        font-size: 10.5pt;
        font-weight: 700;
        color: #1d3a2a;
        line-height: 1.15;
        margin-bottom: 1px;
    }
    .pdf-op-subtitle {
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 7.2pt;
        color: #6f7d74;
        line-height: 1.2;
        margin-bottom: 2px;
    }
    .pdf-op-meta-line {
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 6.6pt;
        color: #b98a2e;
        font-weight: 600;
        line-height: 1.2;
    }

    /* Three Summary Cards (One Row) */
    .pdf-op-summary-kpis {
        width: 100%;
        border-collapse: collapse;
        margin-top: 3px;
        margin-bottom: 5px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .pdf-op-kpi-card {
        background: #ffffff;
        border: 1px solid #e4decd;
        border-top: 3px solid #b98a2e;
        border-radius: 8px;
        padding: 5px 7px;
        text-align: left;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .pdf-op-kpi-num {
        font-family: 'Playfair Display', 'Georgia', serif;
        font-size: 13.5pt;
        font-weight: 700;
        color: #1d3a2a;
        line-height: 1.1;
        margin-bottom: 1px;
    }
    .pdf-op-kpi-lbl {
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 6.6pt;
        font-weight: 700;
        color: #2a5139;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 2px;
        line-height: 1.15;
    }
    .pdf-op-kpi-note {
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 6.1pt;
        color: #6f7d74;
        line-height: 1.2;
    }

    /* Breakdown Cards & Progress Rows */
    .pdf-op-breakdowns-row {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .pdf-op-breakdown-card {
        background: #ffffff;
        border: 1px solid #e4decd;
        border-radius: 8px;
        padding: 5px 7px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .pdf-op-card-head-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
        border-bottom: 1px solid #f1ece1;
    }
    .pdf-op-card-title {
        font-family: 'Playfair Display', 'Times New Roman', 'Georgia', serif;
        font-size: 7.8pt;
        font-weight: 700;
        color: #1d3a2a;
    }
    .pdf-op-card-badge {
        display: inline-block;
        background: #f4efe6;
        color: #2a5139;
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 6.2pt;
        font-weight: 700;
        padding: 1.5px 5px;
        border-radius: 8px;
        border: 1px solid #e4decd;
        line-height: 1.1;
    }
    .pdf-op-bar-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 3.5px;
    }
    .pdf-op-bar-name-cell {
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 6.7pt;
        font-weight: 500;
        color: #1e293b;
        text-align: left;
        padding-bottom: 1.5px;
    }
    .pdf-op-bar-count-cell {
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 6.7pt;
        font-weight: 700;
        color: #1d3a2a;
        text-align: right;
        padding-bottom: 1.5px;
    }
    .pdf-op-bar-track-cell {
        padding: 0;
    }
    .pdf-op-bar-track {
        width: 100%;
        height: 4.5px;
        background-color: #f1ede4;
        border-radius: 3px;
        overflow: hidden;
    }
    .pdf-op-bar-fill-gradient {
        height: 4.5px;
        background-color: #2a5139;
        background: -webkit-linear-gradient(left, #1d3a2a 0%, #b98a2e 100%);
        background: linear-gradient(90deg, #1d3a2a 0%, #b98a2e 100%);
        border-radius: 3px;
    }
    .pdf-op-bar-fill-solid {
        height: 4.5px;
        border-radius: 3px;
    }
    .pdf-op-pct-pill {
        font-size: 6.1pt;
        font-weight: 600;
        color: #6f7d74;
        margin-left: 2px;
    }
    .pdf-op-status-dot {
        display: inline-block;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        vertical-align: middle;
        margin-right: 3px;
    }
    .pdf-op-no-data {
        font-family: 'Figtree', 'Helvetica', 'Arial', sans-serif;
        font-size: 6.8pt;
        font-style: italic;
        color: #6f7d74;
        padding: 6px 4px;
        text-align: center;
    }

    /* ── Dynamic Analytics Charts (Fluid, No Forced Heights) ──────── */
    .pdf-analytics-charts {
        margin-top: 2px;
        margin-bottom: 8px;
    }
    .chart-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 7px 10px;
        margin-bottom: 8px;
    }
    .chart-title-bar {
        font-size: 8.5pt;
        font-weight: bold;
        color: #0f172a;
        margin-bottom: 1px;
        line-height: 1.2;
    }
    .chart-sub-bar {
        font-size: 7pt;
        color: #64748b;
        margin-bottom: 4px;
        line-height: 1.2;
    }
    .chart-two-col {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
    }
    .chart-two-col td {
        vertical-align: top;
    }

    /* ── Table Section Heading & Data Table ──────────────────────── */
    .table-section-heading {
        margin-top: 8px;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-family: 'Helvetica', 'Arial', sans-serif;
    }
    .table-section-title {
        font-size: 9pt;
        font-weight: bold;
        color: #0f172a;
    }
    .table-section-count {
        font-size: 7.2pt;
        color: #64748b;
        font-weight: 600;
    }

    table.data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 7.5pt;
        margin-top: 3px;
        page-break-inside: auto;
    }
    table.data-table thead {
        display: table-header-group;
    }
    table.data-table tfoot {
        display: table-footer-group;
    }
    table.data-table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    table.data-table thead th {
        background-color: #f8fafc;
        color: #334155;
        font-weight: 700;
        font-size: 7.2pt;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 5px 8px;
        text-align: left;
        border: 1px solid #cbd5e1;
        border-bottom: 1.5px solid #94a3b8;
        vertical-align: middle;
    }
    table.data-table tbody tr:nth-child(even) {
        background-color: #fafbfc;
    }
    table.data-table tbody tr:nth-child(odd) {
        background-color: #ffffff;
    }
    table.data-table tbody td {
        padding: 4.5px 8px;
        border: 1px solid #e2e8f0;
        vertical-align: middle;
        color: #1e293b;
        text-align: left;
    }

    /* ── Status Pills & Badges ───────────────────────────────────── */
    .badge {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 10px;
        font-size: 6.8pt;
        font-weight: 700;
        text-transform: capitalize;
        letter-spacing: 0.02em;
        line-height: 1.2;
        white-space: nowrap;
    }
    .badge-success {
        background-color: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    .badge-info {
        background-color: #dbeafe;
        color: #1e40af;
        border: 1px solid #bfdbfe;
    }
    .badge-warning {
        background-color: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    .badge-danger {
        background-color: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    .badge-neutral {
        background-color: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
    }

    .empty-cell {
        color: #94a3b8;
        font-style: italic;
    }
    .truncated-warning {
        margin-top: 6px;
        padding: 5px 8px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
        font-size: 7.2pt;
        border-radius: 4px;
    }
    .no-records {
        text-align: center;
        padding: 20px 10px;
        color: #64748b;
        font-style: italic;
    }

    /* ── Running Footer (Unified for screen & print preview) ─────── */
    .print-running-footer {
        margin-top: 18px;
        padding-top: 6px;
        border-top: 1px solid #e2e8f0;
        font-size: 7pt;
        color: #64748b;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    @media print {
        .print-running-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 16px;
            margin-top: 0;
        }
    }
</style>
</head>
<body>

    <!-- Standardized Parish Header Component -->
    <?php echo $headerHtml; ?>

    <?php if (!empty($meta['parishioners_total']) || !empty($meta['sacraments_total']) || !empty($meta['total_requests'])): ?>
    <!-- KPI Summary Overview -->
    <table class="kpi-summary-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 25%; padding: 2px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)($meta['parishioners_total'] ?? 0)); ?></div>
                    <div class="kpi-lbl">Total Parishioners</div>
                </div>
            </td>
            <td style="width: 25%; padding: 2px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)($meta['sacraments_total'] ?? 0)); ?></div>
                    <div class="kpi-lbl">Sacramental Records</div>
                </div>
            </td>
            <td style="width: 25%; padding: 2px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)($meta['total_requests'] ?? 0)); ?></div>
                    <div class="kpi-lbl">Service Requests</div>
                </div>
            </td>
            <td style="width: 25%; padding: 2px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)(!empty($meta['events_month']) ? $meta['events_month'] : ($meta['requests_pending'] ?? 0))); ?></div>
                    <div class="kpi-lbl"><?php echo !empty($meta['events_month']) ? 'Calendar Events' : 'Pending Requests'; ?></div>
                </div>
            </td>
        </tr>
    </table>
    <?php endif; ?>

    <?php if (!empty($meta['operational_summary'])): 
        $op = $meta['operational_summary'];
    ?>
    <!-- Operational Summary Section -->
    <div class="pdf-op-section">
        <!-- 1 & 2: Header & Subtitle & Metadata Line -->
        <div class="pdf-op-header">
            <div class="pdf-op-title">Operational Summary</div>
            <div class="pdf-op-subtitle">Overview of certificates, blessings, and sacramental service totals</div>
            <div class="pdf-op-meta-line">
                Last updated: <?php echo htmlspecialchars((string)($op['updated_at'] ?? date('M d, Y h:i A')), ENT_QUOTES, 'UTF-8'); ?> &middot; Range: <?php echo htmlspecialchars((string)($op['range_label'] ?? 'All Time'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <!-- 3: Three Summary Cards in One Row -->
        <table class="pdf-op-summary-kpis" cellpadding="0" cellspacing="0">
            <tr>
                <td style="width: 33.333%; padding-right: 4px; vertical-align: top;">
                    <div class="pdf-op-kpi-card">
                        <div class="pdf-op-kpi-num"><?php echo number_format((int)($op['total_certificates'] ?? 0)); ?></div>
                        <div class="pdf-op-kpi-lbl">Total Certificates Created</div>
                        <div class="pdf-op-kpi-note">Official certificates drafted &amp; issued in registry</div>
                    </div>
                </td>
                <td style="width: 33.333%; padding-left: 2px; padding-right: 2px; vertical-align: top;">
                    <div class="pdf-op-kpi-card">
                        <div class="pdf-op-kpi-num"><?php echo number_format((int)($op['blessings_completed'] ?? 0)); ?></div>
                        <div class="pdf-op-kpi-lbl">Blessings Completed</div>
                        <div class="pdf-op-kpi-note"><?php $blTotal = (int)($op['blessings_total'] ?? 0); echo number_format($blTotal); ?> total blessing request<?php echo $blTotal === 1 ? '' : 's'; ?></div>
                    </div>
                </td>
                <td style="width: 33.333%; padding-left: 4px; vertical-align: top;">
                    <div class="pdf-op-kpi-card">
                        <div class="pdf-op-kpi-num"><?php echo number_format((int)($op['services_total'] ?? 0)); ?></div>
                        <div class="pdf-op-kpi-lbl">Sacramental Services</div>
                        <div class="pdf-op-kpi-note">Baptism, Confirmation, Communion, Marriage, Funeral</div>
                    </div>
                </td>
            </tr>
        </table>

        <!-- 4: Two Cards Side by Side (Certificates Breakdown & Blessings Breakdown) -->
        <table class="pdf-op-breakdowns-row" cellpadding="0" cellspacing="0">
            <tr>
                <!-- Certificates Breakdown -->
                <td style="width: 50%; padding-right: 4px; vertical-align: top;">
                    <div class="pdf-op-breakdown-card">
                        <table class="pdf-op-card-head-table" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="text-align: left; vertical-align: middle; padding-bottom: 2px;">
                                    <span class="pdf-op-card-title">Certificates Breakdown</span>
                                </td>
                                <td style="text-align: right; vertical-align: middle; padding-bottom: 2px;">
                                    <span class="pdf-op-card-badge"><?php echo number_format((int)($op['total_certificates'] ?? 0)); ?> Total</span>
                                </td>
                            </tr>
                        </table>
                        <?php if (empty($op['certs_breakdown'])): ?>
                            <div class="pdf-op-no-data">No data for this range</div>
                        <?php else: ?>
                            <?php
                                $maxCert = max(1, max($op['certs_breakdown']));
                                foreach ($op['certs_breakdown'] as $cType => $cCount):
                                    $cPct = round(((int)$cCount / $maxCert) * 100);
                                    $cTypeName = ucwords(str_replace(['_', '-'], ' ', (string)$cType));
                            ?>
                            <table class="pdf-op-bar-table" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="pdf-op-bar-name-cell"><?php echo htmlspecialchars($cTypeName, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="pdf-op-bar-count-cell"><?php echo number_format((int)$cCount); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="pdf-op-bar-track-cell">
                                        <div class="pdf-op-bar-track">
                                            <div class="pdf-op-bar-fill-gradient" style="width: <?php echo max(6, $cPct); ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </td>

                <!-- Blessings Breakdown -->
                <td style="width: 50%; padding-left: 4px; vertical-align: top;">
                    <div class="pdf-op-breakdown-card">
                        <table class="pdf-op-card-head-table" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="text-align: left; vertical-align: middle; padding-bottom: 2px;">
                                    <span class="pdf-op-card-title">Blessings Breakdown</span>
                                </td>
                                <td style="text-align: right; vertical-align: middle; padding-bottom: 2px;">
                                    <span class="pdf-op-card-badge"><?php echo number_format((int)($op['blessings_total'] ?? 0)); ?> Total</span>
                                </td>
                            </tr>
                        </table>
                        <?php if (empty($op['blessings_total'])): ?>
                            <div class="pdf-op-no-data">No data for this range</div>
                        <?php else: ?>
                            <?php
                                $bTot = max(1, (int)$op['blessings_total']);
                                $bStatuses = [
                                    ['label' => 'Completed', 'count' => (int)($op['blessings_completed'] ?? 0), 'color' => '#2f8a57'],
                                    ['label' => 'Pending', 'count' => (int)($op['blessings_pending'] ?? 0), 'color' => '#d9a21b'],
                                    ['label' => 'Approved / Processing', 'count' => (int)($op['blessings_approved'] ?? 0), 'color' => '#3b82a6'],
                                    ['label' => 'Cancelled', 'count' => (int)($op['blessings_cancelled'] ?? 0), 'color' => '#b4533f'],
                                ];
                                foreach ($bStatuses as $st):
                                    $stPct = round(($st['count'] / $bTot) * 100);
                            ?>
                            <table class="pdf-op-bar-table" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="pdf-op-bar-name-cell" style="color: <?php echo $st['color']; ?>; font-weight: 600;">
                                        <span class="pdf-op-status-dot" style="background-color: <?php echo $st['color']; ?>;"></span>
                                        <?php echo htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td class="pdf-op-bar-count-cell"><?php echo number_format($st['count']); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="pdf-op-bar-track-cell">
                                        <div class="pdf-op-bar-track">
                                            <div class="pdf-op-bar-fill-solid" style="background-color: <?php echo $st['color']; ?>; width: <?php echo $st['count'] > 0 ? max(5, $stPct) : 0; ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>

        <!-- 5: One Card: Requests by Blessing Type -->
        <div class="pdf-op-breakdown-card" style="margin-bottom: 7px;">
            <table class="pdf-op-card-head-table" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="text-align: left; vertical-align: middle; padding-bottom: 2px;">
                        <span class="pdf-op-card-title">Requests by Blessing Type</span>
                    </td>
                    <td style="text-align: right; vertical-align: middle; padding-bottom: 2px;">
                        <span class="pdf-op-card-badge"><?php echo count($op['blessings_by_type'] ?? []); ?> Categories</span>
                    </td>
                </tr>
            </table>
            <?php if (empty($op['blessings_by_type'])): ?>
                <div class="pdf-op-no-data">No data for this range</div>
            <?php else: ?>
                <?php
                    $bTot = max(1, (int)($op['blessings_total'] ?? 0));
                    $maxBType = 1;
                    foreach ($op['blessings_by_type'] as $b) {
                        if (($b['total'] ?? 0) > $maxBType) $maxBType = (int)$b['total'];
                    }
                    foreach ($op['blessings_by_type'] as $bType => $bStats):
                        $bCount = (int)($bStats['total'] ?? 0);
                        $bPctOfTotal = round(($bCount / $bTot) * 100);
                        $bWidthPct = round(($bCount / $maxBType) * 100);
                        $bTypeName = ucwords(str_replace(['_', '-'], ' ', (string)$bType));
                ?>
                <table class="pdf-op-bar-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="pdf-op-bar-name-cell"><?php echo htmlspecialchars($bTypeName, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="pdf-op-bar-count-cell">
                            <strong><?php echo number_format($bCount); ?></strong>
                            <span class="pdf-op-pct-pill">(<?php echo $bPctOfTotal; ?>%)</span>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="pdf-op-bar-track-cell">
                            <div class="pdf-op-bar-track">
                                <div class="pdf-op-bar-fill-gradient" style="width: <?php echo max(6, $bWidthPct); ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                </table>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($charts['sacraments']) || !empty($charts['request_status']) || !empty($charts['top_services']) || !empty($charts['parish_growth'])): ?>
    <!-- Structured Analytics Charts Section -->
    <div class="pdf-analytics-charts">

        <!-- Top: Sacramental Records Administered (Full-width bar chart) -->
        <?php if (!empty($charts['sacraments'])): ?>
        <div class="chart-box">
            <div class="chart-title-bar">Sacramental Records Administered</div>
            <div class="chart-sub-bar">Monthly volume by sacrament type &#8211; last 12 months</div>
            <div style="text-align: center; margin-top: 2px;">
                <img src="<?php echo $charts['sacraments']; ?>" style="width: 100%; max-height: 145px; object-fit: contain;">
            </div>
        </div>
        <?php endif; ?>

        <!-- Middle Grid: Request Status Breakdown (left) & Most Requested Services (right) -->
        <?php if (!empty($charts['request_status']) || !empty($charts['top_services'])): ?>
        <table class="chart-two-col" cellpadding="0" cellspacing="0">
            <tr>
                <!-- Left: Request Status Breakdown (Donut chart) -->
                <td style="width: 48%; padding-right: 5px;">
                    <div class="chart-box">
                        <div class="chart-title-bar">Request Status Breakdown</div>
                        <div class="chart-sub-bar">Distribution of all service requests by status</div>
                        <?php if (!empty($charts['request_status'])): ?>
                        <div style="text-align: center; padding: 2px 0;">
                            <img src="<?php echo $charts['request_status']; ?>" style="max-height: 125px; max-width: 95%; object-fit: contain;">
                            <?php if (!empty($meta['total_requests'])): ?>
                            <div style="font-size: 7.5pt; font-weight: bold; color: #1e3a5f; margin-top: 2px;">
                                Total Requests: <?php echo number_format((int)$meta['total_requests']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>

                <!-- Right: Most Requested Services (Horizontal bar chart) -->
                <td style="width: 52%; padding-left: 5px;">
                    <div class="chart-box">
                        <div class="chart-title-bar">Most Requested Services</div>
                        <div class="chart-sub-bar">Top service &amp; certificate types by total volume</div>
                        <?php if (!empty($charts['top_services'])): ?>
                        <div style="text-align: center; padding: 2px 0;">
                            <img src="<?php echo $charts['top_services']; ?>" style="width: 100%; max-height: 125px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
        <?php endif; ?>

        <!-- Bottom: Parishioner Registration Growth line chart (Full-width) -->
        <?php if (!empty($charts['parish_growth'])): ?>
        <div class="chart-box">
            <div class="chart-title-bar">Parishioner Registration Growth</div>
            <div class="chart-sub-bar">Newly registered parishioners per month &#8211; last 12 months</div>
            <div style="text-align: center; margin-top: 2px;">
                <img src="<?php echo $charts['parish_growth']; ?>" style="width: 100%; max-height: 125px; object-fit: contain;">
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <?php if (!empty($columns)): ?>
    <div class="table-section-heading">
        <span class="table-section-title">Parish Records &amp; Activity Log</span>
        <span class="table-section-count"><?php echo number_format(count($rows)); ?> record<?php echo count($rows) === 1 ? '' : 's'; ?></span>
    </div>
    <!-- Data Table with Repeating Header -->
    <table class="data-table">
        <thead>
            <tr>
                <?php foreach ($columns as $colKey => $colLabel): ?>
                    <th><?php echo htmlspecialchars($colLabel, ENT_QUOTES, 'UTF-8'); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="<?php echo count($columns); ?>" class="no-records">
                        No report records matching the specified criteria were found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach (array_keys($columns) as $key): ?>
                            <td><?php echo self::formatCell((string) $key, $row[$key] ?? null); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($truncated): ?>
        <div class="truncated-warning">
            <strong>Notice:</strong> This PDF export shows the first <?php echo number_format(count($rows)); ?> records out of <?php echo number_format($totalRecords); ?> total matching records.
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isDompdf): ?>
    <!-- Continuous Document Footer (for screen and browser print) -->
    <div class="print-running-footer">
        <span class="footer-left">CONFIDENTIAL &mdash; San Lorenzo Ruiz Mission Station Official Document</span>
        <span class="footer-right">Generated on <?php echo date('M d, Y h:i A'); ?></span>
    </div>
    <?php endif; ?>

</body>
</html>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Format an individual table cell based on its key and value.
     */
    private static function formatCell(string $key, mixed $rawVal): string
    {
        if ($rawVal === null || $rawVal === '') {
            return '<span class="empty-cell">—</span>';
        }

        $str = trim((string) $rawVal);

        // 1. Status & Timing badges
        if (in_array($key, ['status', 'timing', 'priority'], true)) {
            return self::renderBadge($key, $str);
        }

        // 2. Date / Datetime fields
        if (
            str_contains($key, 'date') ||
            str_contains($key, '_at') ||
            in_array($key, ['submitted', 'completed', 'issued', 'released', 'revoked', 'updated', 'sent', 'processing_started', 'report_date'], true)
        ) {
            // Full timestamp: 2026-08-25 00:15:12
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?$/', $str)) {
                return htmlspecialchars(date('M d, Y g:i A', strtotime($str)), ENT_QUOTES, 'UTF-8');
            }
            // Date only: 2026-08-25
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                return htmlspecialchars(date('M d, Y', strtotime($str)), ENT_QUOTES, 'UTF-8');
            }
        }

        // 3. Request type / Certificate type / Notification type / Channel
        if (in_array($key, ['request_type', 'certificate_type', 'notification_type', 'reservation_type', 'channel'], true)) {
            return htmlspecialchars(self::humanize($str), ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Render a styled HTML badge pill for status, priority, or timing.
     */
    private static function renderBadge(string $field, string $val): string
    {
        $normalized = strtolower(trim($val));

        $badgeClass = 'badge-neutral';
        if (in_array($normalized, ['approved', 'processing', 'in_processing', 'ready_for_pickup', 'ready'], true)) {
            $badgeClass = 'badge-info';
        } elseif (in_array($normalized, ['completed', 'released', 'issued', 'sent'], true)) {
            $badgeClass = 'badge-success';
        } elseif (in_array($normalized, ['pending', 'submitted', 'due soon', 'review', 'requirements_review', 'draft'], true)) {
            $badgeClass = 'badge-warning';
        } elseif (in_array($normalized, ['overdue', 'rejected', 'cancelled', 'revoked', 'failed', 'urgent', 'high', 'declined'], true)) {
            $badgeClass = 'badge-danger';
        }

        return '<span class="badge ' . $badgeClass . '">' . htmlspecialchars(self::humanize($val), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /**
     * Convert snake_case or hyphenated string to clean Title Case.
     */
    private static function humanize(string $str): string
    {
        $clean = str_replace(['_', '-'], ' ', trim($str));
        return ucwords($clean);
    }

    /**
     * Get Parish Logo as base64 (prioritizes JPEG for zero-dependency Dompdf rendering).
     */
    private static function getLogoBase64(): ?string
    {
        $candidates = [
            'parish-logo.jpg',
            'san-lorenzo-logo.jpg',
            'san-lorenzo-logo-final.jfif',
            'san-lorenzo-logo.png',
        ];

        foreach ($candidates as $filename) {
            $data = self::getAssetBase64($filename);
            if ($data !== null) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Helper to load an image file from assets/img and return a base64 data URI.
     */
    private static function getAssetBase64(string $filename): ?string
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return null;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $mime = match (strtolower($ext)) {
            'png' => 'image/png',
            'jpg', 'jpeg', 'jfif' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg'
        };

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }
}

