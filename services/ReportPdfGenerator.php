<?php
/**
 * ReportPdfGenerator
 * ------------------
 * Institutional PDF Report Generator for San Lorenzo Ruiz Mission Station.
 * Produces standardized, formal parish reports with letterhead, metadata grid,
 * normalized table data, and multi-page footer.
 */

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

final class ReportPdfGenerator
{
    /**
     * Generate and stream/download a PDF report.
     *
     * @param string $reportKey   e.g. 'pending_overdue', 'turnaround'
     * @param string $title       e.g. 'Pending & Overdue Report'
     * @param array  $filters     e.g. ['from' => '2026-08-01', 'to' => '...', 'status' => '...', 'type' => '...']
     * @param array  $data        e.g. ['columns' => [...], 'rows' => [...], 'total' => 12, 'truncated' => false]
     * @param string $generatedBy Name of admin/staff generating report
     * @param string $orientation 'landscape' or 'portrait' (default 'landscape')
     */
    public static function stream(
        string $reportKey,
        string $title,
        array $filters,
        array $data,
        string $generatedBy = 'Administrator',
        string $orientation = 'landscape'
    ): void {
        $html = self::buildHtml($reportKey, $title, $filters, $data, $generatedBy);

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
     * Build the complete standalone HTML string for Dompdf.
     */
    public static function buildHtml(
        string $reportKey,
        string $title,
        array $filters,
        array $data,
        string $generatedBy
    ): string {
        $logoBase64 = self::getLogoBase64();
        $crestBase64 = self::getCrestBase64();

        $reportId = 'RPT-' . date('Y') . '-' . strtoupper(substr(md5($reportKey . microtime()), 0, 6));
        $dateGenerated = date('F d, Y h:i A');

        // Scope description
        $scopeParts = [];
        if (!empty($filters['from']) && !empty($filters['to'])) {
            $scopeParts[] = date('M d, Y', strtotime($filters['from'])) . ' to ' . date('M d, Y', strtotime($filters['to']));
        } elseif (!empty($filters['from'])) {
            $scopeParts[] = 'From ' . date('M d, Y', strtotime($filters['from']));
        } elseif (!empty($filters['to'])) {
            $scopeParts[] = 'Up to ' . date('M d, Y', strtotime($filters['to']));
        } else {
            $scopeParts[] = 'All Records (Complete Overview)';
        }
        $reportScope = implode(', ', $scopeParts);

        // Filter summary string
        $activeFilters = [];
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $activeFilters[] = 'Date: ' . (!empty($filters['from']) ? $filters['from'] : 'Any') . ' to ' . (!empty($filters['to']) ? $filters['to'] : 'Present');
        }
        if (!empty($filters['status'])) {
            $activeFilters[] = 'Status: ' . self::humanize($filters['status']);
        }
        if (!empty($filters['type'])) {
            $activeFilters[] = 'Type: ' . self::humanize($filters['type']);
        }
        $filtersAppliedHtml = !empty($activeFilters)
            ? htmlspecialchars(implode('  |  ', $activeFilters), ENT_QUOTES, 'UTF-8')
            : '<span style="color: #64748b; font-style: italic;">None (Complete Overview)</span>';

        $columns = $data['columns'] ?? [];
        $rows = $data['rows'] ?? [];
        $totalRecords = (int) ($data['total'] ?? count($rows));
        $truncated = !empty($data['truncated']);

        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
    @page {
        margin: 12mm 12mm 15mm 12mm;
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

    /* ── Header / Letterhead ─────────────────────────────────────── */
    .letterhead {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
    }
    .letterhead td {
        vertical-align: middle;
        padding: 0;
    }
    .letterhead-logo-left {
        width: 70px;
        text-align: left;
    }
    .letterhead-logo-left img {
        height: 60px;
        width: auto;
    }
    .letterhead-logo-right {
        width: 70px;
        text-align: right;
    }
    .letterhead-logo-right img {
        height: 56px;
        width: auto;
    }
    .letterhead-center {
        text-align: center;
        padding: 0 10px;
    }
    .letterhead-diocese {
        font-size: 9pt;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1.4px;
        color: #64748b;
        margin-bottom: 2px;
    }
    .letterhead-parish {
        font-size: 13.5pt;
        font-weight: bold;
        letter-spacing: 0.5px;
        color: #1e2d24;
        margin-bottom: 2px;
    }
    .letterhead-location {
        font-size: 8.5pt;
        color: #334155;
        margin-bottom: 2px;
    }
    .letterhead-contact {
        font-size: 7.5pt;
        font-style: italic;
        color: #64748b;
    }

    /* ── Dividing Accent Rules ───────────────────────────────────── */
    .divider-rule {
        width: 100%;
        height: 2px;
        background: #c89b3c; /* Parish Gold */
        margin-top: 8px;
        margin-bottom: 1px;
    }
    .divider-subrule {
        width: 100%;
        height: 0.75px;
        background: #1e2d24; /* Dark Slate */
        margin-bottom: 10px;
    }

    /* ── Report Title Banner ─────────────────────────────────────── */
    .report-title-banner {
        background: #f8fafc;
        border-left: 4px solid #c89b3c;
        border-top: 1px solid #e2e8f0;
        border-right: 1px solid #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        padding: 6px 10px;
        margin-bottom: 8px;
    }
    .report-title-banner h1 {
        font-size: 12.5pt;
        font-weight: bold;
        color: #1e2d24;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        margin: 0;
        padding: 0;
    }

    /* ── Metadata 2-Column Grid ──────────────────────────────────── */
    .meta-box {
        width: 100%;
        border-collapse: collapse;
        background: #fdfdfd;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        margin-bottom: 12px;
    }
    .meta-box td {
        padding: 6px 10px;
        vertical-align: top;
        font-size: 8pt;
    }
    .meta-left {
        width: 50%;
        border-right: 1px solid #e2e8f0;
    }
    .meta-right {
        width: 50%;
    }
    .meta-row {
        margin-bottom: 3px;
    }
    .meta-row:last-child {
        margin-bottom: 0;
    }
    .meta-label {
        font-weight: 600;
        color: #475569;
        display: inline-block;
        min-width: 95px;
    }
    .meta-value {
        color: #0f172a;
        font-weight: 500;
    }

    /* ── Data Table ──────────────────────────────────────────────── */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8pt;
        margin-top: 4px;
    }
    .data-table thead tr {
        background-color: #1e2d24; /* Forest Green / Dark Slate */
    }
    .data-table thead th {
        color: #ffffff;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 7.5pt;
        letter-spacing: 0.4px;
        padding: 6px 7px;
        text-align: left;
        border: 1px solid #1e2d24;
        vertical-align: middle;
    }
    .data-table tbody tr {
        page-break-inside: avoid;
    }
    .data-table tbody tr:nth-child(even) {
        background-color: #f8fafc;
    }
    .data-table tbody tr:nth-child(odd) {
        background-color: #ffffff;
    }
    .data-table tbody td {
        padding: 5.5px 7px;
        border: 1px solid #e2e8f0;
        vertical-align: middle;
        color: #1e293b;
    }

    /* ── Value & Badge Styling ───────────────────────────────────── */
    .badge {
        display: inline-block;
        padding: 2px 6px;
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

    <!-- 1. Formal Institutional Letterhead -->
    <table class="letterhead">
        <tr>
            <td class="letterhead-logo-left">
                <?php if ($logoBase64): ?>
                    <img src="<?php echo $logoBase64; ?>" alt="Parish Logo">
                <?php endif; ?>
            </td>
            <td class="letterhead-center">
                <div class="letterhead-diocese">Diocese of Cotabato</div>
                <div class="letterhead-parish">SAN LORENZO RUIZ MISSION STATION</div>
                <div class="letterhead-location">Poblacion, Aleosan, Cotabato, Philippines</div>
                <div class="letterhead-contact">Official Information System &bull; Contact / Email: tugonparish@gmail.com</div>
            </td>
            <td class="letterhead-logo-right">
                <?php if ($crestBase64): ?>
                    <img src="<?php echo $crestBase64; ?>" alt="Diocese Crest">
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="divider-rule"></div>
    <div class="divider-subrule"></div>

    <!-- 2. Report Title -->
    <div class="report-title-banner">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>

    <!-- 3. Metadata 2-Column Grid -->
    <table class="meta-box">
        <tr>
            <td class="meta-left">
                <div class="meta-row">
                    <span class="meta-label">Generated By:</span>
                    <span class="meta-value"><?php echo htmlspecialchars($generatedBy, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Report Scope:</span>
                    <span class="meta-value"><?php echo htmlspecialchars($reportScope, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Total Records:</span>
                    <span class="meta-value"><?php echo number_format($totalRecords); ?> <?php echo $totalRecords === 1 ? 'record' : 'records'; ?></span>
                </div>
            </td>
            <td class="meta-right">
                <div class="meta-row">
                    <span class="meta-label">Date Generated:</span>
                    <span class="meta-value"><?php echo htmlspecialchars($dateGenerated, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Report ID:</span>
                    <span class="meta-value"><strong><?php echo htmlspecialchars($reportId, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Filters Applied:</span>
                    <span class="meta-value"><?php echo $filtersAppliedHtml; ?></span>
                </div>
            </td>
        </tr>
    </table>

    <!-- 4. Normalized Data Table -->
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
            in_array($key, ['submitted', 'completed', 'issued', 'released', 'revoked', 'updated', 'sent', 'processing_started'], true)
        ) {
            // Full timestamp: 2026-08-25 00:15:12
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?$/', $str)) {
                return htmlspecialchars(date('M d, Y h:i A', strtotime($str)), ENT_QUOTES, 'UTF-8');
            }
            // Date only: 2026-08-25
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                return htmlspecialchars(date('M d, Y', strtotime($str)), ENT_QUOTES, 'UTF-8');
            }
        }

        // 3. Request type / Certificate type / Notification type
        if (in_array($key, ['request_type', 'certificate_type', 'notification_type', 'reservation_type', 'channel'], true)) {
            return htmlspecialchars(self::humanize($str), ENT_QUOTES, 'UTF-8');
        }

        // 4. Fallback snake_case humanization if string looks like an identifier
        if (preg_match('/^[a-z]+(?:_[a-z0-9]+)+$/', $str)) {
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
        if (in_array($normalized, ['approved', 'completed', 'issued', 'released', 'sent'], true)) {
            $badgeClass = 'badge-success';
        } elseif (in_array($normalized, ['pending', 'due soon', 'review', 'requirements_review', 'processing', 'draft'], true)) {
            $badgeClass = 'badge-warning';
        } elseif (in_array($normalized, ['overdue', 'rejected', 'cancelled', 'revoked', 'failed', 'urgent', 'high'], true)) {
            $badgeClass = 'badge-danger';
        }

        $label = self::humanize($val);
        return '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
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
     * Get Diocese Crest as base64 (prioritizes JPEG for zero-dependency Dompdf rendering).
     */
    private static function getCrestBase64(): ?string
    {
        $candidates = [
            'archdiocese-crest.jpg',
            'archdiocese-crest.jfif',
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
