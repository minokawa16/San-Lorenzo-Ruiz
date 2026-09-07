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
     * @param string      $orientation 'landscape' or 'portrait' (default 'landscape')
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
        $html = self::buildHtml($reportKey, $title, $filters, $data, $generatedBy, $subtitle, $charts, $meta);

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
        $size = 8;
        $color = [0.45, 0.45, 0.45]; // Muted gray

        $width = $canvas->get_width();
        $height = $canvas->get_height();

        // Footer left: Confidentiality note
        $canvas->page_text(
            35,
            $height - 25,
            "CONFIDENTIAL  —  San Lorenzo Ruiz Mission Station Official Document",
            $font,
            $size,
            $color
        );

        // Footer right: Page X of Y
        $canvas->page_text(
            $width - 95,
            $height - 25,
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
     *
     * Produces:
     *                 ARCHDIOCESE OF COTABATO
     *              SAN LORENZO RUIZ MISSION STATION
     *                    Aleosan, North Cotabato
     * --------------------------------------------------
     *                     [REPORT TITLE]
     *                   [REPORT SUBTITLE]
     *                     Filters: [...]
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
            <?php if (!empty($subtitle) && strcasecmp(trim($subtitle), trim($reportTitle)) !== 0): ?>
                <div class="report-subtitle"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div class="report-timestamp">Generated on: <?php echo htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="report-filters">Filters: <?php echo htmlspecialchars($filtersFormatted, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build the complete standalone HTML string for Dompdf.
     */
    public static function buildHtml(
        string $reportKey,
        string $title,
        array $filters,
        array $data,
        string $generatedBy = 'Administrator',
        ?string $subtitle = null,
        array $charts = [],
        array $meta = []
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
<style>
    @page {
        margin: 15mm;
    }
    *, *::before, *::after {
        box-sizing: border-box;
    }
    body {
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 8.5pt;
        line-height: 1.35;
        color: #1e293b;
        background: #ffffff;
        margin: 0;
        padding: 0;
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
        width: 85px;
        text-align: left;
    }
    .parish-logo-img {
        height: 72px;
        width: auto;
        display: block;
    }
    .parish-logo-placeholder {
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background: #1e2d24;
        display: block;
    }
    .letterhead-text-cell {
        text-align: center;
        padding: 0 10px;
    }
    .letterhead-balance-cell {
        width: 85px; /* Symmetrical balance matching logo width */
    }
    .diocese-name {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 11pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #1e293b;
        margin-bottom: 3px;
        line-height: 1.2;
    }
    .parish-name {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 16pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #0f172a;
        margin-bottom: 3px;
        line-height: 1.2;
    }
    .parish-location {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 10pt;
        color: #334155;
        line-height: 1.2;
    }

    /* ── Gold Divider with Centered Latin Cross ───────────────────── */
    .gold-cross-divider {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6px;
        margin-bottom: 12px;
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
        font-size: 14pt;
        font-weight: bold;
        line-height: 1;
        padding: 0 4px;
        font-family: 'Times New Roman', serif;
    }

    /* ── Report Title & Filters ──────────────────────────────────── */
    .report-header-section {
        margin-bottom: 12px;
    }
    .report-title {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 15pt;
        font-weight: bold;
        color: #0f172a;
        margin-bottom: 2px;
        line-height: 1.2;
    }
    .report-subtitle {
        font-size: 10pt;
        color: #475569;
        font-weight: 500;
        margin-bottom: 3px;
    }
    .report-timestamp {
        font-size: 8pt;
        color: #64748b;
        margin-top: 2px;
        margin-bottom: 2px;
    }
    .report-filters {
        font-size: 8.5pt;
        color: #334155;
        margin-top: 2px;
        margin-bottom: 2px;
    }

    /* ── KPI Overview Summary ────────────────────────────────────── */
    .kpi-summary-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
        margin-bottom: 14px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .kpi-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 8px 10px;
        text-align: center;
    }
    .kpi-num {
        font-size: 13pt;
        font-weight: bold;
        color: #1e3a5f;
        line-height: 1.2;
    }
    .kpi-lbl {
        font-size: 6.5pt;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        font-weight: 600;
        margin-top: 2px;
    }

    /* ── Dynamic Chart Grid & Page-Break Safeguards ──────────────── */
    .pdf-analytics-charts {
        margin-top: 6px;
        margin-bottom: 14px;
    }
    .chart-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
        page-break-inside: avoid;
        break-inside: avoid;
        margin-bottom: 12px;
    }
    .chart-title-bar {
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 9.5pt;
        font-weight: bold;
        color: #0f172a;
        margin-bottom: 2px;
        line-height: 1.2;
    }
    .chart-sub-bar {
        font-size: 7.5pt;
        color: #64748b;
        margin-bottom: 6px;
        line-height: 1.2;
    }
    .chart-two-col {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .chart-two-col td {
        vertical-align: top;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    /* ── Data Table with Multi-Page Header Repeating ─────────────── */
    table.data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8pt;
        margin-top: 6px;
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
        background-color: #ffffff;
        color: #0f172a;
        font-weight: bold;
        font-size: 8pt;
        letter-spacing: 0.2px;
        padding: 6px 8px;
        text-align: center;
        border: 1px solid #94a3b8;
        vertical-align: middle;
    }
    table.data-table tbody tr:nth-child(even) {
        background-color: #fafbfc;
    }
    table.data-table tbody tr:nth-child(odd) {
        background-color: #ffffff;
    }
    table.data-table tbody td {
        padding: 5.5px 8px;
        border: 1px solid #cbd5e1;
        vertical-align: middle;
        color: #1e293b;
        text-align: left;
    }

    /* ── Value & Badge Styling ───────────────────────────────────── */
    .badge {
        display: inline-block;
        padding: 1.5px 5px;
        border-radius: 3px;
        font-size: 7pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        line-height: 1.2;
    }
    .badge-success {
        background-color: #dcfce7;
        color: #166534;
        border: 1px solid #86efac;
    }
    .badge-warning {
        background-color: #fef3c7;
        color: #92400e;
        border: 1px solid #fcd34d;
    }
    .badge-danger {
        background-color: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
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
        margin-top: 8px;
        padding: 6px 10px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
        font-size: 7.5pt;
        border-radius: 3px;
    }
    .no-records {
        text-align: center;
        padding: 24px 10px;
        color: #64748b;
        font-style: italic;
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
            <td style="width: 25%; padding: 3px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)($meta['parishioners_total'] ?? 0)); ?></div>
                    <div class="kpi-lbl">Total Parishioners</div>
                </div>
            </td>
            <td style="width: 25%; padding: 3px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)($meta['sacraments_total'] ?? 0)); ?></div>
                    <div class="kpi-lbl">Sacramental Records</div>
                </div>
            </td>
            <td style="width: 25%; padding: 3px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)($meta['total_requests'] ?? 0)); ?></div>
                    <div class="kpi-lbl">Service Requests</div>
                </div>
            </td>
            <td style="width: 25%; padding: 3px;">
                <div class="kpi-box">
                    <div class="kpi-num"><?php echo number_format((int)($meta['events_month'] ?? 0)); ?></div>
                    <div class="kpi-lbl">Calendar Events (Month)</div>
                </div>
            </td>
        </tr>
    </table>
    <?php endif; ?>

    <?php if (!empty($charts['sacraments']) || !empty($charts['request_status']) || !empty($charts['top_services']) || !empty($charts['parish_growth'])): ?>
    <!-- Structured Analytics Charts Section -->
    <div class="pdf-analytics-charts">

        <!-- Top: Sacramental Records Administered (Full-width bar chart) -->
        <?php if (!empty($charts['sacraments'])): ?>
        <div class="chart-box" style="page-break-inside: avoid;">
            <div class="chart-title-bar">Sacramental Records Administered</div>
            <div class="chart-sub-bar">Monthly volume by sacrament type &#8211; last 12 months</div>
            <div style="text-align: center; margin-top: 4px;">
                <img src="<?php echo $charts['sacraments']; ?>" style="width: 100%; max-height: 230px; object-fit: contain;">
            </div>
        </div>
        <?php endif; ?>

        <!-- Middle Grid: Request Status Breakdown (left) & Most Requested Services (right) -->
        <?php if (!empty($charts['request_status']) || !empty($charts['top_services'])): ?>
        <table class="chart-two-col" cellpadding="0" cellspacing="0">
            <tr>
                <!-- Left: Request Status Breakdown (Donut chart) -->
                <td style="width: 48%; padding-right: 6px;">
                    <div class="chart-box" style="page-break-inside: avoid; height: 100%;">
                        <div class="chart-title-bar">Request Status Breakdown</div>
                        <div class="chart-sub-bar">Distribution of all service requests by status</div>
                        <?php if (!empty($charts['request_status'])): ?>
                        <div style="text-align: center; padding: 6px 0;">
                            <img src="<?php echo $charts['request_status']; ?>" style="max-height: 185px; max-width: 95%; object-fit: contain;">
                            <?php if (!empty($meta['total_requests'])): ?>
                            <div style="font-size: 8pt; font-weight: bold; color: #1e3a5f; margin-top: 4px;">
                                Total Requests: <?php echo number_format((int)$meta['total_requests']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>

                <!-- Right: Most Requested Services (Horizontal bar chart) -->
                <td style="width: 52%; padding-left: 6px;">
                    <div class="chart-box" style="page-break-inside: avoid; height: 100%;">
                        <div class="chart-title-bar">Most Requested Services</div>
                        <div class="chart-sub-bar">Top service &amp; certificate types by volume</div>
                        <?php if (!empty($charts['top_services'])): ?>
                        <div style="text-align: center; padding: 6px 0;">
                            <img src="<?php echo $charts['top_services']; ?>" style="width: 100%; max-height: 185px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
        <?php endif; ?>

        <!-- Bottom: Parishioner Registration Growth line chart (Full-width) -->
        <?php if (!empty($charts['parish_growth'])): ?>
        <div class="chart-box" style="page-break-inside: avoid;">
            <div class="chart-title-bar">Parishioner Registration Growth</div>
            <div class="chart-sub-bar">Newly registered parishioners per month &#8211; last 12 months</div>
            <div style="text-align: center; margin-top: 4px;">
                <img src="<?php echo $charts['parish_growth']; ?>" style="width: 100%; max-height: 210px; object-fit: contain;">
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <?php if (!empty($columns)): ?>
    <div style="font-family: 'Times New Roman', serif; font-size: 11pt; font-weight: bold; color: #0f172a; margin-top: 14px; margin-bottom: 4px; page-break-after: avoid;">
        Parish Records &amp; Activity Log
    </div>
    <!-- Data Table -->
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
                return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
            }
            // Date only: 2026-08-25
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
            }
        }

        // 3. Request type / Certificate type / Notification type / Channel
        if (in_array($key, ['request_type', 'certificate_type', 'notification_type', 'reservation_type', 'channel'], true)) {
            return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
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
        if (in_array($normalized, ['approved', 'completed', 'issued', 'released', 'sent'], true)) {
            $badgeClass = 'badge-success';
        } elseif (in_array($normalized, ['pending', 'due soon', 'review', 'requirements_review', 'processing', 'draft'], true)) {
            $badgeClass = 'badge-warning';
        } elseif (in_array($normalized, ['overdue', 'rejected', 'cancelled', 'revoked', 'failed', 'urgent', 'high'], true)) {
            $badgeClass = 'badge-danger';
        }

        return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
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
