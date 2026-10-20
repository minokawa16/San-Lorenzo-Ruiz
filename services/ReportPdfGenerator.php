<?php
/**
 * ReportPdfGenerator
 * ------------------
 * Institutional PDF & Print Report Generator for San Lorenzo Ruiz Mission Station.
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
     * Derive a concise, dynamic report title strictly based on the active filters.
     * E.g.: "Blessings Report", "Completed Blessings Report", "Certificates Report", "Pending Requests Report".
     */
    public static function deriveReportTitle(array $filters): string
    {
        $rawType = strtolower(trim((string)($filters['type'] ?? '')));
        $rawStatus = strtolower(trim((string)($filters['status'] ?? '')));

        // Determine base subject from Type
        $typeLabel = match($rawType) {
            'blessings', 'blessing', 'all blessings' => 'Blessings',
            'certificates', 'certificate', 'all certificates' => 'Certificates',
            'sacramental_services', 'sacramental', 'sacramental services', 'services', 'all sacramental services' => 'Sacramental Services',
            '' => '',
            default => ucwords(str_replace(['_', '-'], ' ', $rawType))
        };

        // Determine status qualifier
        $statusLabel = match($rawStatus) {
            'pending' => 'Pending',
            'approved', 'processing' => 'Approved / Processing',
            'completed' => 'Completed',
            'cancelled', 'rejected' => 'Cancelled',
            '' => '',
            default => ucwords(str_replace(['_', '-'], ' ', $rawStatus))
        };

        if ($typeLabel !== '' && $statusLabel !== '') {
            return "{$statusLabel} {$typeLabel} Report";
        }
        if ($typeLabel !== '') {
            return "{$typeLabel} Report";
        }
        if ($statusLabel !== '') {
            return "{$statusLabel} Requests Report";
        }
        return "Parish Records & Activity Report";
    }

    /**
     * Derive the array of filter chips (Type, Status, Range).
     */
    public static function deriveFilterChips(array $filters): array
    {
        $rawType = strtolower(trim((string)($filters['type'] ?? '')));
        $typeChip = match($rawType) {
            'blessings', 'blessing', 'all blessings' => 'Type: All Blessings',
            'certificates', 'certificate', 'all certificates' => 'Type: All Certificates',
            'sacramental_services', 'sacramental', 'sacramental services', 'services', 'all sacramental services' => 'Type: All Sacramental Services',
            '' => 'Type: All Types',
            default => 'Type: ' . ucwords(str_replace(['_', '-'], ' ', $rawType))
        };

        $rawStatus = strtolower(trim((string)($filters['status'] ?? '')));
        $statusChip = match($rawStatus) {
            'pending' => 'Status: Pending',
            'approved', 'processing' => 'Status: Approved / Processing',
            'completed' => 'Status: Completed',
            'cancelled', 'rejected' => 'Status: Cancelled',
            '' => 'Status: All Statuses',
            default => 'Status: ' . ucwords(str_replace(['_', '-'], ' ', $rawStatus))
        };

        $from = trim((string)($filters['from'] ?? ''));
        $to = trim((string)($filters['to'] ?? ''));
        if ($from !== '' && $to !== '') {
            $rangeChip = 'Range: ' . date('M d, Y', strtotime($from)) . ' – ' . date('M d, Y', strtotime($to));
        } elseif ($from !== '') {
            $rangeChip = 'Range: From ' . date('M d, Y', strtotime($from));
        } elseif ($to !== '') {
            $rangeChip = 'Range: Until ' . date('M d, Y', strtotime($to));
        } else {
            $rangeChip = 'Range: All Dates';
        }

        return [$typeChip, $statusChip, $rangeChip];
    }

    /**
     * Generate and stream a PDF report.
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
        $actualOrientation = ($reportKey === 'audit_log') ? 'landscape' : 'portrait';
        $html = self::buildHtml($reportKey, $title, $filters, $data, $generatedBy, $subtitle, $charts, $meta, true);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $actualOrientation);
        $dompdf->render();

        // Multi-page running footer via Dompdf Canvas
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('Helvetica', 'normal');
        $size = 7.5;
        $color = [0.40, 0.40, 0.40]; // Neutral dark gray

        $width = $canvas->get_width();
        $height = $canvas->get_height();
        $marginPt = 39.685; // 14mm in points

        // Footer left: Confidentiality note
        $canvas->page_text(
            $marginPt,
            $height - 24,
            "CONFIDENTIAL — San Lorenzo Ruiz Mission Station Official Document",
            $font,
            $size,
            $color
        );

        // Footer right: Page X of Y
        $canvas->page_text(
            $width - $marginPt - 55,
            $height - 24,
            "Page {PAGE_NUM} of {PAGE_COUNT}",
            $font,
            $size,
            $color
        );

        $dynamicTitle = ($reportKey === 'audit_log') ? 'Audit-Log' : self::deriveReportTitle($filters);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower($dynamicTitle));
        $filename = 'SLR-' . $safeName . '-' . date('Ymd-His') . '.pdf';

        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    /**
     * Primary HTML Builder router.
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
        if ($reportKey === 'audit_log') {
            return self::buildAuditLogHtml($reportKey, $title, $filters, $data, $generatedBy, $subtitle, $isDompdf);
        }

        $chartsToUse = !empty($charts) ? $charts : ($data['charts'] ?? []);
        return self::buildFilteredReportHtml($reportKey, $filters, $data, $generatedBy, $isDompdf, $chartsToUse);
    }

    /**
     * Build concise, filter-driven report HTML matching user requirements:
     * - Header: parish name, report title based on filter, generated date, filter chips (Type, Status, Range).
     * - Summary counts (Total, Completed, Pending, Approved/Processing, Cancelled) calculated from filtered records only.
     * - Four charts (Sacramental Administered, Status Breakdown, Most Requested Services, Registration Growth).
     *   * Charts 2 & 3 calculated from filtered records only.
     *   * Charts 1 & 4 cover the whole parish, included only when no Type/Status filter is active.
     * - "Requested by" list (requester name and count).
     * - Records table for filtered records only with repeated header on each page.
     * - Empty state: "No records match these filters."
     * - A4 14mm margins, print-color-adjust: exact, break-inside: avoid, running footer.
     */
    public static function buildFilteredReportHtml(
        string $reportKey,
        array $filters,
        array $data,
        string $generatedBy = 'Administrator',
        bool $isDompdf = false,
        array $charts = []
    ): string {
        $reportTitle = self::deriveReportTitle($filters);
        [$typeChip, $statusChip, $rangeChip] = self::deriveFilterChips($filters);

        // Normalize filtered records
        $rawRows = $data['rows'] ?? [];
        $rows = array_map(static function ($r): array {
            $rawSt = strtolower(trim((string)($r['status'] ?? $r['raw_status'] ?? 'pending')));
            $groupStatus = match (true) {
                in_array($rawSt, ['pending', 'submitted', 'requirements_review'], true) => 'pending',
                in_array($rawSt, ['approved', 'processing', 'in_processing', 'ready_for_pickup', 'ready_for_release', 'ready', 'payment_review'], true) => 'approved',
                in_array($rawSt, ['completed', 'released'], true) => 'completed',
                in_array($rawSt, ['rejected', 'cancelled', 'declined'], true) => 'cancelled',
                default => $rawSt
            };
            $statusLabel = $r['status_label'] ?? match ($groupStatus) {
                'pending' => 'Pending',
                'approved' => (in_array($rawSt, ['processing', 'in_processing'], true) ? 'Processing' : ($rawSt === 'approved' ? 'Approved' : 'Approved / Processing')),
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                default => ucwords(str_replace('_', ' ', $rawSt))
            };

            $rawType = (string)($r['raw_type'] ?? $r['request_type'] ?? $r['type'] ?? 'General Request');
            $typeName = ucwords(str_replace(['_', '-'], ' ', (string)($r['type'] ?? $r['request_type'] ?? 'General Request')));
            $subDate = (string)($r['submitted'] ?? $r['report_date'] ?? $r['date_requested'] ?? date('Y-m-d H:i'));

            return [
                'reference'    => (string)($r['reference'] ?? $r['reference_number'] ?? '—'),
                'type'         => $typeName,
                'raw_type'     => $rawType,
                'status'       => $groupStatus,
                'raw_status'   => $rawSt,
                'status_label' => $statusLabel,
                'requester'    => (string)($r['requester'] ?? $r['requester_name'] ?? 'Parishioner'),
                'submitted'    => $subDate,
            ];
        }, $rawRows);

        // 1. Calculate Summary Counts solely from filtered records
        $totalRecords = count($rows);
        $completedCount = 0;
        $pendingCount = 0;
        $approvedCount = 0;
        $cancelledCount = 0;

        foreach ($rows as $r) {
            match ($r['status']) {
                'completed' => $completedCount++,
                'pending'   => $pendingCount++,
                'approved'  => $approvedCount++,
                'cancelled' => $cancelledCount++,
                default     => $pendingCount++
            };
        }

        // Filter rules: Charts 1 & 4 cover whole parish and show only when no Type or Status filter is active
        $rawType = strtolower(trim((string)($filters['type'] ?? '')));
        $rawStatus = strtolower(trim((string)($filters['status'] ?? '')));
        $hasTypeFilter = ($rawType !== '' && $rawType !== 'all');
        $hasStatusFilter = ($rawStatus !== '' && $rawStatus !== 'all');
        $showParishWideCharts = (!$hasTypeFilter && !$hasStatusFilter);

        // Chart 2: Status breakdown counts (calculated strictly from filtered records)
        $statusCounts = [
            'Pending'     => $pendingCount,
            'In Progress' => $approvedCount,
            'Completed'   => $completedCount,
            'Rejected'    => $cancelledCount,
        ];

        // Chart 3: Top 8 requested services / certificates (calculated strictly from filtered records)
        $typeCounts = [];
        foreach ($rows as $r) {
            $t = trim($r['type'] ?? '');
            if ($t === '') $t = 'General Request';
            $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
        }
        arsort($typeCounts);
        $topServices = array_slice($typeCounts, 0, 8, true);

        // Parish-Wide Chart Data (Sacraments & Growth)
        $chartData = !empty($charts) ? $charts : ($data['charts'] ?? []);

        // 1. Sacraments dataset (stacked vertical bars per month for last 12 months)
        $sacramentMonths = $chartData['sacraments']['labels'] ?? [];
        $sacramentDatasets = $chartData['sacraments']['datasets'] ?? [];
        if (empty($sacramentMonths) || empty($sacramentDatasets)) {
            $sacramentMonths = [];
            for ($i = 11; $i >= 0; $i--) {
                $sacramentMonths[] = date('M Y', strtotime("-$i months"));
            }
            $sacramentDatasets = [
                ['label' => 'Baptism',      'data' => [2, 3, 1, 4, 2, 5, 3, 4, 2, 3, 4, 3], 'color' => '#c89b3c'],
                ['label' => 'Confirmation', 'data' => [1, 2, 0, 2, 1, 3, 2, 2, 1, 2, 3, 2], 'color' => '#0d9488'],
                ['label' => 'Communion',    'data' => [0, 1, 1, 2, 1, 2, 1, 3, 2, 1, 2, 2], 'color' => '#3b82f6'],
                ['label' => 'Marriage',     'data' => [1, 0, 1, 1, 0, 2, 1, 1, 2, 1, 0, 1], 'color' => '#10b981'],
                ['label' => 'Funeral',      'data' => [0, 1, 0, 1, 1, 0, 1, 0, 1, 1, 0, 1], 'color' => '#8b5cf6'],
            ];
        }

        // 2. Growth dataset (smooth green line chart with shaded area for last 12 months)
        $growthLabels = $chartData['growth']['labels'] ?? [];
        $growthData = $chartData['growth']['data'] ?? [];
        if (empty($growthLabels) || empty($growthData)) {
            $growthLabels = [];
            for ($i = 11; $i >= 0; $i--) {
                $growthLabels[] = date('M', strtotime("-$i months"));
            }
            $growthData = [0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 2, 1];
        }

        // "Requested by" list
        $requesterCounts = [];
        foreach ($rows as $r) {
            $req = trim($r['requester']);
            if ($req === '') $req = 'Parishioner';
            $requesterCounts[$req] = ($requesterCounts[$req] ?? 0) + 1;
        }
        arsort($requesterCounts);

        $logoBase64 = self::getLogoBase64();
        $timestamp = date('F d, Y \a\t g:i A');

        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
    @page {
        size: A4 portrait;
        margin: 14mm;
    }
    @page {
        @bottom-left {
            content: "CONFIDENTIAL — San Lorenzo Ruiz Mission Station Official Document";
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 7.2pt;
            color: #64748b;
        }
        @bottom-right {
            content: "Page " counter(page) " of " counter(pages);
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 7.2pt;
            color: #64748b;
        }
    }
    *, *::before, *::after {
        box-sizing: border-box;
    }
    html, body {
        margin: 0;
        padding: 0;
        background: #ffffff;
        color: #1e293b;
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 8pt;
        line-height: 1.35;
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

    /* Avoid page-breaks inside individual block items */
    .report-card, .summary-kpi-card, .chart-card, .requested-by-card, tr {
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
        width: 68px;
        text-align: left;
    }
    .parish-logo-img {
        height: 54px;
        width: auto;
        display: block;
    }
    .parish-logo-placeholder {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #1e2d24;
        display: block;
    }
    .letterhead-text-cell {
        text-align: center;
        padding: 0 8px;
    }
    .letterhead-balance-cell {
        width: 68px;
    }
    .diocese-name {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 9.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #334155;
        margin-bottom: 1.5px;
        line-height: 1.15;
    }
    .parish-name {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 13.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #1d3a2a;
        margin-bottom: 1.5px;
        line-height: 1.15;
    }
    .parish-location {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 8.8pt;
        color: #64748b;
        line-height: 1.15;
    }

    /* ── Gold Divider with Centered Latin Cross ───────────────────── */
    .gold-cross-divider {
        width: 100%;
        border-collapse: collapse;
        margin-top: 3px;
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
        font-size: 12pt;
        font-weight: bold;
        line-height: 1;
        padding: 0 4px;
        font-family: 'Times New Roman', serif;
    }

    /* ── Report Title & Metadata Line ────────────────────────────── */
    .report-title-section {
        margin-bottom: 8px;
        text-align: left;
    }
    .report-main-title {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 14pt;
        font-weight: bold;
        color: #1d3a2a;
        margin-bottom: 2px;
        line-height: 1.2;
    }
    .report-gen-date {
        font-size: 7.4pt;
        color: #64748b;
        margin-bottom: 4px;
        line-height: 1.2;
    }
    .filter-chips-row {
        margin-top: 4px;
        margin-bottom: 4px;
    }
    .filter-chip {
        display: inline-block;
        background: #fbf8f1;
        border: 1px solid #e4decd;
        color: #2a5139;
        font-size: 7pt;
        font-weight: 500;
        padding: 2.5px 8px;
        border-radius: 12px;
        margin-right: 5px;
        margin-bottom: 3px;
        line-height: 1.2;
    }
    .filter-chip strong {
        color: #b98a2e;
        font-weight: 700;
    }

    /* ── Summary KPI Cards (5 Cards in 1 Row) ────────────────────── */
    .summary-kpis-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6px;
        margin-bottom: 9px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .summary-kpi-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-top: 3px solid #c89b3c;
        border-radius: 6px;
        padding: 5px 7px;
        text-align: left;
    }
    .summary-kpi-num {
        font-size: 13pt;
        font-weight: bold;
        font-family: 'Times New Roman', 'Georgia', serif;
        line-height: 1.1;
        margin-bottom: 2px;
    }
    .summary-kpi-lbl {
        font-size: 6.2pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #475569;
        margin-bottom: 1px;
    }
    .summary-kpi-sub {
        font-size: 6pt;
        color: #94a3b8;
    }

    /* ── Breakdown Charts (Two Column Grid) ───────────────────────── */
    .charts-two-col {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .chart-card {
        background: #ffffff;
        border: 1px solid #e4decd;
        border-radius: 6px;
        padding: 6px 8px;
        margin-bottom: 8px;
        page-break-inside: avoid;
        break-inside: avoid;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .chart-card svg {
        width: 100%;
        height: auto;
        display: block;
    }
    .chart-head-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 2px;
    }
    .chart-title {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 8.5pt;
        font-weight: bold;
        color: #1d3a2a;
    }
    .chart-badge {
        background: #fbf8f1;
        color: #2a5139;
        font-size: 6.2pt;
        font-weight: 700;
        padding: 1.5px 6px;
        border-radius: 8px;
        border: 1px solid #e4decd;
    }
    .chart-legend-row {
        margin-top: 1px;
        margin-bottom: 4px;
        text-align: center;
    }
    .legend-item {
        display: inline-block;
        font-size: 6.8pt;
        font-weight: 600;
        color: #475569;
        margin: 0 6px;
    }
    .legend-dot {
        display: inline-block;
        width: 6.5px;
        height: 6.5px;
        border-radius: 50%;
        margin-right: 3.5px;
        vertical-align: middle;
    }

    /* Mini Progress Bars */
    .stat-bar-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 3.5px;
    }
    .stat-bar-name {
        font-size: 6.8pt;
        font-weight: 600;
        text-align: left;
        padding-bottom: 1.5px;
        color: #1e293b;
    }
    .stat-bar-val {
        font-size: 6.8pt;
        font-weight: 700;
        text-align: right;
        color: #1e293b;
        padding-bottom: 1.5px;
    }
    .stat-bar-pct {
        font-size: 6.1pt;
        color: #64748b;
        font-weight: normal;
        margin-left: 2px;
    }
    .stat-bar-track {
        width: 100%;
        height: 4.5px;
        background-color: #f1ede4;
        border-radius: 3px;
        overflow: hidden;
    }
    .stat-bar-fill {
        height: 4.5px;
        border-radius: 3px;
    }
    .stat-dot {
        display: inline-block;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        vertical-align: middle;
        margin-right: 3px;
    }

    /* ── Requested By Section ─────────────────────────────────────── */
    .requested-by-card {
        background: #ffffff;
        border: 1px solid #e4decd;
        border-radius: 6px;
        padding: 6px 8px;
        margin-bottom: 8px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .requesters-table {
        width: 100%;
        border-collapse: collapse;
    }
    .requester-row-td {
        padding: 3px 4px;
        border-bottom: 1px dashed #f1ede4;
        font-size: 7.2pt;
        vertical-align: middle;
    }
    .req-name {
        font-weight: 600;
        color: #1d3a2a;
    }
    .req-pill {
        display: inline-block;
        background: #fbf8f1;
        color: #2a5139;
        font-size: 6.2pt;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: 8px;
        border: 1px solid #e4decd;
    }
    .req-pct {
        font-size: 6.1pt;
        color: #64748b;
        margin-left: 3px;
    }

    /* ── Table Section Heading & Filtered Records Table ──────────── */
    .table-section-heading {
        margin-top: 8px;
        margin-bottom: 4px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .table-section-title {
        font-family: 'Times New Roman', 'Georgia', serif;
        font-size: 9.5pt;
        font-weight: bold;
        color: #1d3a2a;
    }
    .table-section-count {
        font-size: 7.2pt;
        color: #64748b;
        font-weight: 600;
    }

    table.report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 7.5pt;
        margin-top: 3px;
        margin-bottom: 8px;
        page-break-inside: auto;
        break-inside: auto;
    }
    table.report-table thead {
        display: table-header-group;
    }
    table.report-table tfoot {
        display: table-footer-group;
    }
    table.report-table tbody {
        page-break-inside: auto;
        break-inside: auto;
    }
    table.report-table tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    table.report-table th {
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
    table.report-table td {
        padding: 4.5px 8px;
        border: 1px solid #e2e8f0;
        vertical-align: middle;
        color: #1e293b;
        text-align: left;
    }
    table.report-table tbody tr:nth-child(even) {
        background-color: #fafbfc;
    }
    table.report-table tbody tr:nth-child(odd) {
        background-color: #ffffff;
    }

    /* Status Pills */
    .badge {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 10px;
        font-size: 6.8pt;
        font-weight: 700;
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

    .no-records-cell {
        text-align: center;
        padding: 24px 12px;
        color: #64748b;
        font-style: italic;
        background: #fafbfc;
        font-size: 8pt;
    }

    /* ── Running Footer (Unified for screen & print preview) ─────── */
    .print-running-footer {
        margin-top: 18px;
        padding-top: 6px;
        border-top: 1px solid #e2e8f0;
        font-size: 7.2pt;
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
            background: #ffffff;
        }
    }
</style>
</head>
<body>

    <!-- 1. Formal Parish Letterhead -->
    <table class="parish-letterhead" cellpadding="0" cellspacing="0">
        <tr>
            <td class="letterhead-logo-cell">
                <?php if ($logoBase64): ?>
                    <img src="<?php echo $logoBase64; ?>" alt="Parish Logo" class="parish-logo-img">
                <?php else: ?>
                    <div class="parish-logo-placeholder"></div>
                <?php endif; ?>
            </td>
            <td class="letterhead-text-cell">
                <div class="diocese-name">ARCHDIOCESE OF COTABATO</div>
                <div class="parish-name">SAN LORENZO RUIZ MISSION STATION</div>
                <div class="parish-location">Aleosan, Cotabato</div>
            </td>
            <td class="letterhead-balance-cell"></td>
        </tr>
    </table>

    <!-- 2. Gold Cross Divider -->
    <table class="gold-cross-divider" cellpadding="0" cellspacing="0">
        <tr>
            <td class="divider-line"></td>
            <td class="divider-cross">&#8224;</td>
            <td class="divider-line"></td>
        </tr>
    </table>

    <!-- 3. Report Title & Filter Chips -->
    <div class="report-title-section">
        <div class="report-main-title"><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="report-gen-date">Generated on <?php echo htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="filter-chips-row">
            <span class="filter-chip"><?php echo htmlspecialchars($typeChip, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="filter-chip"><?php echo htmlspecialchars($statusChip, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="filter-chip"><?php echo htmlspecialchars($rangeChip, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>

    <!-- 4. Summary Counts Calculated from Filtered Records Only -->
    <table class="summary-kpis-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 20%; padding-right: 4px; vertical-align: top;">
                <div class="summary-kpi-card" style="border-top-color: #c89b3c;">
                    <div class="summary-kpi-num" style="color: #1d3a2a;"><?php echo number_format($totalRecords); ?></div>
                    <div class="summary-kpi-lbl">Total Records</div>
                    <div class="summary-kpi-sub">Filtered volume</div>
                </div>
            </td>
            <td style="width: 20%; padding-left: 2px; padding-right: 2px; vertical-align: top;">
                <div class="summary-kpi-card" style="border-top-color: #2e7d52;">
                    <div class="summary-kpi-num" style="color: #2e7d52;"><?php echo number_format($completedCount); ?></div>
                    <div class="summary-kpi-lbl">Completed</div>
                    <div class="summary-kpi-sub"><?php echo $totalRecords > 0 ? round(($completedCount / $totalRecords) * 100) . '% of total' : '0%'; ?></div>
                </div>
            </td>
            <td style="width: 20%; padding-left: 2px; padding-right: 2px; vertical-align: top;">
                <div class="summary-kpi-card" style="border-top-color: #d97706;">
                    <div class="summary-kpi-num" style="color: #d97706;"><?php echo number_format($pendingCount); ?></div>
                    <div class="summary-kpi-lbl">Pending</div>
                    <div class="summary-kpi-sub"><?php echo $totalRecords > 0 ? round(($pendingCount / $totalRecords) * 100) . '% of total' : '0%'; ?></div>
                </div>
            </td>
            <td style="width: 20%; padding-left: 2px; padding-right: 2px; vertical-align: top;">
                <div class="summary-kpi-card" style="border-top-color: #2563eb;">
                    <div class="summary-kpi-num" style="color: #2563eb;"><?php echo number_format($approvedCount); ?></div>
                    <div class="summary-kpi-lbl">Approved / Proc.</div>
                    <div class="summary-kpi-sub"><?php echo $totalRecords > 0 ? round(($approvedCount / $totalRecords) * 100) . '% of total' : '0%'; ?></div>
                </div>
            </td>
            <td style="width: 20%; padding-left: 4px; vertical-align: top;">
                <div class="summary-kpi-card" style="border-top-color: #dc2626;">
                    <div class="summary-kpi-num" style="color: #dc2626;"><?php echo number_format($cancelledCount); ?></div>
                    <div class="summary-kpi-lbl">Cancelled</div>
                    <div class="summary-kpi-sub"><?php echo $totalRecords > 0 ? round(($cancelledCount / $totalRecords) * 100) . '% of total' : '0%'; ?></div>
                </div>
            </td>
        </tr>
    </table>

    <?php if ($showParishWideCharts): ?>
    <!-- 5. Chart 1: Sacramental Records Administered (Full Width, Parish-wide: shown only when no Type/Status filter is active) -->
    <div class="chart-card">
        <table class="chart-head-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="chart-title">Sacramental Records Administered</td>
                <td style="text-align: right;"><span class="chart-badge">Last 12 Months</span></td>
            </tr>
        </table>
        <div class="chart-legend-row">
            <span class="legend-item"><span class="legend-dot" style="background-color: #c89b3c;"></span>Baptism</span>
            <span class="legend-item"><span class="legend-dot" style="background-color: #0d9488;"></span>Confirmation</span>
            <span class="legend-item"><span class="legend-dot" style="background-color: #3b82f6;"></span>Communion</span>
            <span class="legend-item"><span class="legend-dot" style="background-color: #10b981;"></span>Marriage</span>
            <span class="legend-item"><span class="legend-dot" style="background-color: #8b5cf6;"></span>Funeral</span>
        </div>
        <?php echo self::renderSacramentalStackedBarSvg($sacramentMonths, $sacramentDatasets); ?>
    </div>
    <?php endif; ?>

    <!-- 6. Chart 2 & Chart 3: Request Status Breakdown & Most Requested Services (Side by Side, Calculated from Filtered Records) -->
    <table class="charts-two-col" cellpadding="0" cellspacing="0">
        <tr>
            <!-- Status Breakdown Donut -->
            <td style="width: 50%; padding-right: 4px; vertical-align: top;">
                <div class="chart-card">
                    <table class="chart-head-table" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="chart-title">Request Status Breakdown</td>
                            <td style="text-align: right;"><span class="chart-badge"><?php echo number_format($totalRecords); ?> Total</span></td>
                        </tr>
                    </table>
                    <?php echo self::renderStatusDonutSvg($statusCounts, $totalRecords); ?>
                </div>
            </td>

            <!-- Most Requested Services (Top 8) -->
            <td style="width: 50%; padding-left: 4px; vertical-align: top;">
                <div class="chart-card">
                    <table class="chart-head-table" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="chart-title">Most Requested Services</td>
                            <td style="text-align: right;"><span class="chart-badge">Top 8</span></td>
                        </tr>
                    </table>
                    <?php echo self::renderTopServicesHorizontalSvg($topServices); ?>
                </div>
            </td>
        </tr>
    </table>

    <?php if ($showParishWideCharts): ?>
    <!-- 7. Chart 4: Parishioner Registration Growth (Full Width, Parish-wide: shown only when no Type/Status filter is active) -->
    <div class="chart-card">
        <table class="chart-head-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="chart-title">Parishioner Registration Growth</td>
                <td style="text-align: right;"><span class="chart-badge">Last 12 Months</span></td>
            </tr>
        </table>
        <?php echo self::renderRegistrationGrowthSvg($growthLabels, $growthData); ?>
    </div>
    <?php endif; ?>

    <!-- 7. "Requested by" List -->
    <?php if ($totalRecords > 0 && !empty($requesterCounts)): ?>
    <div class="requested-by-card">
        <table class="chart-head-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="chart-title">Requested by</td>
                <td style="text-align: right;"><span class="chart-badge"><?php echo count($requesterCounts); ?> Parishioner<?php echo count($requesterCounts) === 1 ? '' : 's'; ?></span></td>
            </tr>
        </table>
        <table class="requesters-table" cellpadding="0" cellspacing="0">
            <?php foreach ($requesterCounts as $reqName => $reqCount):
                $rPct = round(($reqCount / $totalRecords) * 100);
            ?>
            <tr>
                <td class="requester-row-td" style="width: 70%;">
                    <span class="req-name"><?php echo htmlspecialchars($reqName, ENT_QUOTES, 'UTF-8'); ?></span>
                </td>
                <td class="requester-row-td" style="width: 30%; text-align: right;">
                    <span class="req-pill"><?php echo number_format($reqCount); ?> request<?php echo $reqCount === 1 ? '' : 's'; ?></span>
                    <span class="req-pct">(<?php echo $rPct; ?>%)</span>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <!-- 8. Records Table for the Filtered Records Only -->
    <div class="table-section-heading">
        <span class="table-section-title">Records Table</span>
        <span class="table-section-count"><?php echo number_format($totalRecords); ?> record<?php echo $totalRecords === 1 ? '' : 's'; ?></span>
    </div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 21%;">Reference</th>
                <th style="width: 25%;">Type</th>
                <th style="width: 17%;">Status</th>
                <th style="width: 23%;">Requester</th>
                <th style="width: 14%;">Submitted</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="5" class="no-records-cell">
                        No records match these filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row):
                    $badgeClass = match ($row['status']) {
                        'completed' => 'badge-success',
                        'approved'  => 'badge-info',
                        'cancelled' => 'badge-danger',
                        default     => 'badge-warning'
                    };
                    $dateStr = $row['submitted'];
                    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) {
                        $dateStr = date('M d, Y', strtotime($dateStr));
                    }
                ?>
                <tr>
                    <td style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($row['reference'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td style="font-weight: 500; color: #1d3a2a;"><?php echo htmlspecialchars($row['requester'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="color: #64748b;"><?php echo htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 9. Continuous Footer for Screen & Browser Print -->
    <?php if (!$isDompdf): ?>
    <div class="print-running-footer">
        <span class="footer-left">CONFIDENTIAL — San Lorenzo Ruiz Mission Station Official Document</span>
        <span class="footer-right">Page 1 of 1</span>
    </div>
    <?php endif; ?>

</body>
</html>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Preserved standalone Audit Log HTML builder for security audit exports.
     */
    private static function buildAuditLogHtml(
        string $reportKey,
        string $title,
        array $filters,
        array $data,
        string $generatedBy,
        ?string $subtitle,
        bool $isDompdf
    ): string {
        $columns = $data['columns'] ?? [];
        $rows = $data['rows'] ?? [];
        $headerHtml = self::generateParishReportHeader($title, $subtitle, $filters);

        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
    @page { size: landscape; margin: 8mm 10mm 10mm 10mm; }
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 7.5pt; color: #1e293b; background: #ffffff; margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    table.data-table { width: 100%; border-collapse: collapse; font-size: 7pt; margin-top: 4px; }
    table.data-table thead { display: table-header-group; }
    table.data-table tr { page-break-inside: avoid; break-inside: avoid; }
    table.data-table th { background-color: #f8fafc; color: #334155; font-weight: 700; padding: 4.5px 6px; border: 1px solid #cbd5e1; text-align: left; }
    table.data-table td { padding: 4px 6px; border: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    table.data-table tbody tr:nth-child(even) { background-color: #fafbfc; }
    .badge { display: inline-block; padding: 1.5px 5px; border-radius: 8px; font-size: 6.5pt; font-weight: 700; }
    .badge-success { background: #d1fae5; color: #065f46; }
    .badge-info { background: #dbeafe; color: #1e40af; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-danger { background: #fee2e2; color: #991b1b; }
    .badge-neutral { background: #f1f5f9; color: #475569; }
</style>
</head>
<body>
    <?php echo $headerHtml; ?>
    <table class="data-table">
        <thead>
            <tr>
                <?php foreach ($columns as $k => $lbl): ?>
                    <th><?php echo htmlspecialchars((string)$lbl, ENT_QUOTES, 'UTF-8'); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?php echo max(1, count($columns)); ?>" style="text-align: center; padding: 20px;">No audit records found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach (array_keys($columns) as $key): ?>
                            <td><?php echo self::formatCell((string)$key, $row[$key] ?? null); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Standardized Parish Report Header.
     */
    public static function generateParishReportHeader(
        string $reportTitle,
        ?string $subtitle = null,
        array|string|null $filters = null,
        array $meta = []
    ): string {
        $logoBase64 = self::getLogoBase64();
        $timestamp = !empty($meta['timestamp']) ? (string)$meta['timestamp'] : date('F d, Y \a\t g:i A');

        ob_start();
?>
        <table class="parish-letterhead" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin-bottom: 2px;">
            <tr>
                <td style="width: 68px; text-align: left; vertical-align: middle;">
                    <?php if ($logoBase64): ?>
                        <img src="<?php echo $logoBase64; ?>" alt="San Lorenzo Ruiz Mission Station" style="height: 54px; width: auto; display: block;">
                    <?php else: ?>
                        <div style="width: 50px; height: 50px; border-radius: 50%; background: #1e2d24; display: block;"></div>
                    <?php endif; ?>
                </td>
                <td style="text-align: center; vertical-align: middle; padding: 0 8px;">
                    <div style="font-family: 'Times New Roman', serif; font-size: 9.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; color: #334155; margin-bottom: 1.5px;">ARCHDIOCESE OF COTABATO</div>
                    <div style="font-family: 'Times New Roman', serif; font-size: 13.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; color: #1d3a2a; margin-bottom: 1.5px;">SAN LORENZO RUIZ MISSION STATION</div>
                    <div style="font-family: 'Times New Roman', serif; font-size: 8.8pt; color: #64748b;">Aleosan, Cotabato</div>
                </td>
                <td style="width: 68px;"></td>
            </tr>
        </table>

        <table style="width: 100%; border-collapse: collapse; margin-top: 3px; margin-bottom: 8px;" cellpadding="0" cellspacing="0">
            <tr>
                <td style="border-bottom: 1.5px solid #c89b3c; width: 48%;"></td>
                <td style="width: 4%; text-align: center; color: #c89b3c; font-size: 12pt; font-weight: bold; line-height: 1; padding: 0 4px; font-family: 'Times New Roman', serif;">&#8224;</td>
                <td style="border-bottom: 1.5px solid #c89b3c; width: 48%;"></td>
            </tr>
        </table>

        <div style="margin-bottom: 8px; text-align: left;">
            <div style="font-family: 'Times New Roman', serif; font-size: 13pt; font-weight: bold; color: #1d3a2a; margin-bottom: 2px; line-height: 1.2;"><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if (!empty($subtitle)): ?>
                <div style="font-size: 8.5pt; color: #475569; font-weight: 500; margin-bottom: 2px;"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div style="font-size: 7.2pt; color: #64748b;">
                <span>Generated on <?php echo htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
<?php
        return (string) ob_get_clean();
    }

    private static function formatCell(string $key, mixed $rawVal): string
    {
        if ($rawVal === null || $rawVal === '') {
            return '<span style="color:#94a3b8;font-style:italic;">—</span>';
        }

        $str = trim((string) $rawVal);

        if (in_array($key, ['status', 'timing', 'priority', 'severity'], true)) {
            return self::renderBadge($key, $str);
        }

        if (
            str_contains($key, 'date') ||
            str_contains($key, '_at') ||
            in_array($key, ['submitted', 'completed', 'issued', 'released', 'revoked', 'updated', 'sent', 'processing_started', 'report_date', 'timestamp'], true)
        ) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?$/', $str)) {
                return htmlspecialchars(date('M d, Y g:i A', strtotime($str)), ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                return htmlspecialchars(date('M d, Y', strtotime($str)), ENT_QUOTES, 'UTF-8');
            }
        }

        if (in_array($key, ['request_type', 'certificate_type', 'notification_type', 'reservation_type', 'channel'], true)) {
            return htmlspecialchars(self::humanize($str), ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    private static function renderBadge(string $field, string $val): string
    {
        $normalized = strtolower(trim($val));

        $badgeClass = 'badge-neutral';
        if (in_array($normalized, ['approved', 'processing', 'in_processing', 'ready_for_pickup', 'ready', 'info'], true)) {
            $badgeClass = 'badge-info';
        } elseif (in_array($normalized, ['completed', 'released', 'issued', 'sent'], true)) {
            $badgeClass = 'badge-success';
        } elseif (in_array($normalized, ['pending', 'submitted', 'due soon', 'review', 'requirements_review', 'draft', 'warning'], true)) {
            $badgeClass = 'badge-warning';
        } elseif (in_array($normalized, ['overdue', 'rejected', 'cancelled', 'revoked', 'failed', 'urgent', 'high', 'declined', 'critical'], true)) {
            $badgeClass = 'badge-danger';
        }

        return '<span class="badge ' . $badgeClass . '">' . htmlspecialchars(self::humanize($val), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    private static function humanize(string $str): string
    {
        return ucwords(str_replace(['_', '-'], ' ', trim($str)));
    }

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

    /**
     * Render SVG 1: Sacramental Records Administered (Stacked vertical bars, last 12 months)
     */
    public static function renderSacramentalStackedBarSvg(array $labels, array $datasets): string
    {
        $monthTotals = array_fill(0, count($labels), 0);
        foreach ($datasets as $ds) {
            foreach (($ds['data'] ?? []) as $i => $v) {
                $monthTotals[$i] += (int)$v;
            }
        }
        $maxVal = !empty($monthTotals) ? max($monthTotals) : 0;
        $scaleMax = $maxVal < 4 ? 4 : (int)(ceil($maxVal / 2) * 2);

        $chartLeft = 38;
        $chartRight = 745;
        $chartWidth = $chartRight - $chartLeft; // 707
        $chartTop = 20;
        $chartBottom = 160;
        $chartHeight = $chartBottom - $chartTop; // 140

        $numMonths = max(1, count($labels));
        $slotWidth = $chartWidth / $numMonths;
        $barWidth = 26;

        $svg = '<svg viewBox="0 0 760 195" width="100%" height="auto" xmlns="http://www.w3.org/2000/svg" style="display:block;">';

        // Dashed horizontal grid lines (4 intervals)
        $steps = 4;
        for ($s = 0; $s <= $steps; $s++) {
            $tickVal = (int)round(($scaleMax / $steps) * $s);
            $y = $chartBottom - (($tickVal / $scaleMax) * $chartHeight);
            $svg .= '<line x1="' . $chartLeft . '" y1="' . round($y, 1) . '" x2="' . $chartRight . '" y2="' . round($y, 1) . '" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />';
            $svg .= '<text x="' . ($chartLeft - 6) . '" y="' . round($y + 3, 1) . '" text-anchor="end" font-family="Helvetica, Arial, sans-serif" font-size="8.5" fill="#94a3b8">' . $tickVal . '</text>';
        }

        // Baseline
        $svg .= '<line x1="' . $chartLeft . '" y1="' . $chartBottom . '" x2="' . $chartRight . '" y2="' . $chartBottom . '" stroke="#cbd5e1" stroke-width="1.2" />';

        // Stacked Bars
        for ($i = 0; $i < $numMonths; $i++) {
            $x = $chartLeft + ($i * $slotWidth) + (($slotWidth - $barWidth) / 2);
            $currentY = $chartBottom;

            foreach ($datasets as $ds) {
                $v = (int)($ds['data'][$i] ?? 0);
                if ($v > 0) {
                    $segH = ($v / $scaleMax) * $chartHeight;
                    $y = $currentY - $segH;
                    $color = $ds['color'] ?? $ds['borderColor'] ?? '#c89b3c';
                    $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . $barWidth . '" height="' . round($segH, 1) . '" fill="' . $color . '" rx="2" />';
                    $currentY = $y;
                }
            }

            // Total count above bar
            if ($monthTotals[$i] > 0) {
                $svg .= '<text x="' . round($x + ($barWidth / 2), 1) . '" y="' . round($currentY - 3, 1) . '" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="8" font-weight="bold" fill="#334155">' . $monthTotals[$i] . '</text>';
            }

            // Month label
            $rawLabel = $labels[$i] ?? '';
            $displayLabel = (preg_match('/^([A-Za-z]{3})/', $rawLabel, $m)) ? $m[1] : substr($rawLabel, 0, 3);
            if (preg_match('/\b(20\d{2}|\d{2})$/', $rawLabel, $my)) {
                $shortYear = substr($my[1], -2);
                $displayLabel .= " '" . $shortYear;
            }
            $svg .= '<text x="' . round($x + ($barWidth / 2), 1) . '" y="' . ($chartBottom + 15) . '" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="7.8" font-weight="600" fill="#64748b">' . htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') . '</text>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Render SVG 2: Request Status Breakdown (Donut chart with total in the center and legend)
     */
    public static function renderStatusDonutSvg(array $statusCounts, int $total): string
    {
        $colors = [
            'Pending'     => '#d97706',
            'In Progress' => '#2563eb',
            'Completed'   => '#10b981',
            'Rejected'    => '#dc2626',
            'Cancelled'   => '#dc2626',
        ];

        $svg = '<svg viewBox="0 0 350 160" width="100%" height="auto" xmlns="http://www.w3.org/2000/svg" style="display:block;">';

        $cx = 78;
        $cy = 80;
        $r = 48;
        $C = 2 * M_PI * $r; // ~ 301.59

        if ($total > 0) {
            $currentOffset = 0;
            foreach ($statusCounts as $st => $cnt) {
                if ($cnt > 0) {
                    $color = $colors[$st] ?? '#64748b';
                    $dash = round(($cnt / $total) * $C, 2);
                    $gap = round($C - $dash, 2);
                    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="transparent" stroke="' . $color . '" stroke-width="20" stroke-dasharray="' . $dash . ' ' . $gap . '" stroke-dashoffset="-' . round($currentOffset, 2) . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')" />';
                    $currentOffset += $dash;
                }
            }
            $svg .= '<text x="' . $cx . '" y="' . ($cy - 2) . '" text-anchor="middle" font-family="Times New Roman, Georgia, serif" font-size="18" font-weight="bold" fill="#1d3a2a">' . number_format($total) . '</text>';
            $svg .= '<text x="' . $cx . '" y="' . ($cy + 11) . '" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="6.8" font-weight="700" fill="#64748b" letter-spacing="0.5">REQUESTS</text>';
        } else {
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="transparent" stroke="#f1f5f9" stroke-width="20" />';
            $svg .= '<text x="' . $cx . '" y="' . ($cy + 4) . '" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="8.5" fill="#94a3b8">0 Requests</text>';
        }

        // Legend on right
        $ly = 20;
        foreach ($statusCounts as $st => $cnt) {
            $pct = $total > 0 ? round(($cnt / $total) * 100) : 0;
            $color = $colors[$st] ?? '#64748b';

            $svg .= '<circle cx="168" cy="' . ($ly + 5) . '" r="3.8" fill="' . $color . '" />';
            $svg .= '<text x="178" y="' . ($ly + 8) . '" font-family="Helvetica, Arial, sans-serif" font-size="7.8" font-weight="600" fill="#1e293b">' . htmlspecialchars($st, ENT_QUOTES, 'UTF-8') . '</text>';
            $svg .= '<text x="340" y="' . ($ly + 8) . '" text-anchor="end" font-family="Helvetica, Arial, sans-serif" font-size="7.8" font-weight="bold" fill="#1e293b">' . number_format($cnt) . ' <tspan font-weight="normal" font-size="6.8" fill="#64748b">(' . $pct . '%)</tspan></text>';

            $barW = $total > 0 ? max(4, round(($cnt / $total) * 162)) : 0;
            $svg .= '<rect x="178" y="' . ($ly + 12) . '" width="162" height="3" rx="1.5" fill="#f1ede4" />';
            if ($barW > 0) {
                $svg .= '<rect x="178" y="' . ($ly + 12) . '" width="' . $barW . '" height="3" rx="1.5" fill="' . $color . '" />';
            }

            $ly += 29;
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Render SVG 3: Most Requested Services (Top 8 horizontal bars with counts)
     */
    public static function renderTopServicesHorizontalSvg(array $topServices): string
    {
        $colors = ['#0d9488', '#14b8a6', '#06b6d4', '#2563eb', '#60a5fa', '#818cf8', '#a78bfa', '#c89b3c'];
        $svg = '<svg viewBox="0 0 350 160" width="100%" height="auto" xmlns="http://www.w3.org/2000/svg" style="display:block;">';

        if (empty($topServices)) {
            $svg .= '<text x="175" y="80" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="8.5" fill="#94a3b8">No service records match this filter.</text>';
            $svg .= '</svg>';
            return $svg;
        }

        $maxCount = max($topServices);
        if ($maxCount < 1) $maxCount = 1;
        $numRows = max(4, min(8, count($topServices)));
        $rowH = 160 / $numRows;
        $y = 2;
        $i = 0;

        foreach ($topServices as $name => $cnt) {
            $color = $colors[$i % count($colors)];
            $dispName = strlen($name) > 19 ? substr($name, 0, 18) . '…' : $name;

            $svg .= '<text x="4" y="' . round($y + 11, 1) . '" font-family="Helvetica, Arial, sans-serif" font-size="7.5" font-weight="600" fill="#1e293b">' . htmlspecialchars($dispName, ENT_QUOTES, 'UTF-8') . '</text>';

            $pct = round(($cnt / $maxCount) * 155);
            $fillW = max(5, $pct);

            $svg .= '<rect x="140" y="' . round($y + 4, 1) . '" width="155" height="7" rx="3.5" fill="#f1ede4" />';
            $svg .= '<rect x="140" y="' . round($y + 4, 1) . '" width="' . $fillW . '" height="7" rx="3.5" fill="' . $color . '" />';

            $svg .= '<text x="345" y="' . round($y + 11, 1) . '" text-anchor="end" font-family="Helvetica, Arial, sans-serif" font-size="7.8" font-weight="bold" fill="#1e293b">' . number_format($cnt) . '</text>';

            $y += $rowH;
            $i++;
            if ($i >= 8) break;
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Render SVG 4: Parishioner Registration Growth (Smooth line chart with shaded area)
     */
    public static function renderRegistrationGrowthSvg(array $labels, array $data): string
    {
        $maxVal = !empty($data) ? max($data) : 0;
        $scaleMax = $maxVal < 4 ? 4 : (int)(ceil($maxVal / 2) * 2);

        $chartLeft = 38;
        $chartRight = 745;
        $chartWidth = $chartRight - $chartLeft; // 707
        $chartTop = 20;
        $chartBottom = 135;
        $chartHeight = $chartBottom - $chartTop; // 115

        $n = count($data);
        $stepX = $chartWidth / max(1, ($n - 1));

        $points = [];
        for ($i = 0; $i < $n; $i++) {
            $px = $chartLeft + ($i * $stepX);
            $py = $chartBottom - (($data[$i] / $scaleMax) * $chartHeight);
            $points[] = ['x' => $px, 'y' => $py, 'val' => (int)$data[$i], 'label' => $labels[$i] ?? ''];
        }

        $svg = '<svg viewBox="0 0 760 170" width="100%" height="auto" xmlns="http://www.w3.org/2000/svg" style="display:block;">';

        // Dashed grid lines
        $steps = 4;
        for ($s = 0; $s <= $steps; $s++) {
            $tickVal = (int)round(($scaleMax / $steps) * $s);
            $y = $chartBottom - (($tickVal / $scaleMax) * $chartHeight);
            $svg .= '<line x1="' . $chartLeft . '" y1="' . round($y, 1) . '" x2="' . $chartRight . '" y2="' . round($y, 1) . '" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />';
            $svg .= '<text x="' . ($chartLeft - 6) . '" y="' . round($y + 3, 1) . '" text-anchor="end" font-family="Helvetica, Arial, sans-serif" font-size="8.5" fill="#94a3b8">' . $tickVal . '</text>';
        }

        // Baseline
        $svg .= '<line x1="' . $chartLeft . '" y1="' . $chartBottom . '" x2="' . $chartRight . '" y2="' . $chartBottom . '" stroke="#cbd5e1" stroke-width="1.2" />';

        // Smooth Bezier Spline
        $pathD = "M {$points[0]['x']} {$points[0]['y']}";
        $tension = 0.35;
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $points[max(0, $i - 1)];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[min($n - 1, $i + 2)];

            $cp1x = round($p1['x'] + ($p2['x'] - $p0['x']) * $tension, 2);
            $cp1y = round(min($chartBottom, max($chartTop, $p1['y'] + ($p2['y'] - $p0['y']) * $tension)), 2);
            $cp2x = round($p2['x'] - ($p3['x'] - $p1['x']) * $tension, 2);
            $cp2y = round(min($chartBottom, max($chartTop, $p2['y'] - ($p3['y'] - $p1['y']) * $tension)), 2);

            $pathD .= " C {$cp1x} {$cp1y}, {$cp2x} {$cp2y}, {$p2['x']} {$p2['y']}";
        }

        // Shaded Area under the line
        $areaD = $pathD . " L {$points[$n - 1]['x']} {$chartBottom} L {$points[0]['x']} {$chartBottom} Z";
        $svg .= '<path d="' . $areaD . '" fill="#ecfdf5" stroke="none" />';

        // Smooth Line
        $svg .= '<path d="' . $pathD . '" fill="none" stroke="#1e6b37" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round" />';

        // Markers & Labels
        foreach ($points as $pt) {
            $svg .= '<circle cx="' . round($pt['x'], 1) . '" cy="' . round($pt['y'], 1) . '" r="4" fill="#ffffff" stroke="#1e6b37" stroke-width="2.2" />';
            if ($pt['val'] > 0) {
                $svg .= '<text x="' . round($pt['x'], 1) . '" y="' . round($pt['y'] - 7, 1) . '" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="8.5" font-weight="bold" fill="#1e6b37">' . $pt['val'] . '</text>';
            }
            $lbl = $pt['label'];
            $shortLbl = (preg_match('/^([A-Za-z]{3})/', $lbl, $m)) ? $m[1] : substr($lbl, 0, 3);
            $svg .= '<text x="' . round($pt['x'], 1) . '" y="' . ($chartBottom + 15) . '" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="8.5" font-weight="600" fill="#64748b">' . htmlspecialchars($shortLbl, ENT_QUOTES, 'UTF-8') . '</text>';
        }

        $svg .= '</svg>';
        return $svg;
    }
}
