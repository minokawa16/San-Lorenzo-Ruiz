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

        return self::buildFilteredReportHtml($reportKey, $filters, $data, $generatedBy, $isDompdf);
    }

    /**
     * Build concise, filter-driven report HTML matching user requirements:
     * - Header: parish name, report title based on filter, generated date, filter chips (Type, Status, Range).
     * - Summary counts (Total, Completed, Pending, Approved/Processing, Cancelled) calculated from filtered records only.
     * - Status breakdown chart only if > 1 status is present.
     * - "By type" chart only if > 1 type is present.
     * - "Requested by" list (requester name and count).
     * - Records table for filtered records only with repeated header on each page.
     * - Empty state: "No records match these filters."
     * - No unrelated sections.
     * - A4 14mm margins, print-color-adjust: exact, break-inside: avoid, running footer.
     */
    public static function buildFilteredReportHtml(
        string $reportKey,
        array $filters,
        array $data,
        string $generatedBy = 'Administrator',
        bool $isDompdf = false
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

        // 2. Status breakdown (only if > 1 status is present)
        $activeStatuses = [];
        if ($completedCount > 0) {
            $activeStatuses['Completed'] = [
                'count' => $completedCount,
                'pct'   => $totalRecords > 0 ? round(($completedCount / $totalRecords) * 100) : 0,
                'color' => '#2e7d52',
            ];
        }
        if ($approvedCount > 0) {
            $activeStatuses['Approved / Processing'] = [
                'count' => $approvedCount,
                'pct'   => $totalRecords > 0 ? round(($approvedCount / $totalRecords) * 100) : 0,
                'color' => '#2563eb',
            ];
        }
        if ($pendingCount > 0) {
            $activeStatuses['Pending'] = [
                'count' => $pendingCount,
                'pct'   => $totalRecords > 0 ? round(($pendingCount / $totalRecords) * 100) : 0,
                'color' => '#d97706',
            ];
        }
        if ($cancelledCount > 0) {
            $activeStatuses['Cancelled'] = [
                'count' => $cancelledCount,
                'pct'   => $totalRecords > 0 ? round(($cancelledCount / $totalRecords) * 100) : 0,
                'color' => '#dc2626',
            ];
        }
        $showStatusChart = count($activeStatuses) > 1;

        // 3. "By type" breakdown (only if > 1 type is present)
        $typeCounts = [];
        foreach ($rows as $r) {
            $t = $r['type'];
            $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
        }
        arsort($typeCounts);
        $showTypeChart = count($typeCounts) > 1;
        $maxTypeCount = !empty($typeCounts) ? max($typeCounts) : 1;

        // 4. "Requested by" list
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
    }
    .chart-head-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5px;
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

    <!-- 5 & 6. Status Breakdown Chart and "By Type" Chart (Conditional) -->
    <?php if ($showStatusChart || $showTypeChart): ?>
        <?php if ($showStatusChart && $showTypeChart): ?>
            <table class="charts-two-col" cellpadding="0" cellspacing="0">
                <tr>
                    <!-- Status Distribution Card -->
                    <td style="width: 50%; padding-right: 4px; vertical-align: top;">
                        <div class="chart-card">
                            <table class="chart-head-table" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="chart-title">Status Breakdown</td>
                                    <td style="text-align: right;"><span class="chart-badge"><?php echo count($activeStatuses); ?> Statuses</span></td>
                                </tr>
                            </table>
                            <?php foreach ($activeStatuses as $stLabel => $stData): ?>
                                <table class="stat-bar-table" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td class="stat-bar-name">
                                            <span class="stat-dot" style="background-color: <?php echo $stData['color']; ?>;"></span>
                                            <?php echo htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td class="stat-bar-val">
                                            <?php echo number_format($stData['count']); ?>
                                            <span class="stat-bar-pct">(<?php echo $stData['pct']; ?>%)</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="padding: 0;">
                                            <div class="stat-bar-track">
                                                <div class="stat-bar-fill" style="background-color: <?php echo $stData['color']; ?>; width: <?php echo max(5, $stData['pct']); ?>%;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            <?php endforeach; ?>
                        </div>
                    </td>

                    <!-- By Type Breakdown Card -->
                    <td style="width: 50%; padding-left: 4px; vertical-align: top;">
                        <div class="chart-card">
                            <table class="chart-head-table" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="chart-title">Requests by Type</td>
                                    <td style="text-align: right;"><span class="chart-badge"><?php echo count($typeCounts); ?> Types</span></td>
                                </tr>
                            </table>
                            <?php foreach ($typeCounts as $tLabel => $tCount):
                                $tPct = $totalRecords > 0 ? round(($tCount / $totalRecords) * 100) : 0;
                                $tBarPct = round(($tCount / $maxTypeCount) * 100);
                            ?>
                                <table class="stat-bar-table" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td class="stat-bar-name"><?php echo htmlspecialchars($tLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="stat-bar-val">
                                            <?php echo number_format($tCount); ?>
                                            <span class="stat-bar-pct">(<?php echo $tPct; ?>%)</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="padding: 0;">
                                            <div class="stat-bar-track">
                                                <div class="stat-bar-fill" style="background: linear-gradient(90deg, #1d3a2a 0%, #c89b3c 100%); background-color: #2a5139; width: <?php echo max(6, $tBarPct); ?>%;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            </table>
        <?php elseif ($showStatusChart): ?>
            <!-- Single full-width Status Card -->
            <div class="chart-card">
                <table class="chart-head-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="chart-title">Status Breakdown</td>
                        <td style="text-align: right;"><span class="chart-badge"><?php echo count($activeStatuses); ?> Statuses</span></td>
                    </tr>
                </table>
                <?php foreach ($activeStatuses as $stLabel => $stData): ?>
                    <table class="stat-bar-table" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="stat-bar-name">
                                <span class="stat-dot" style="background-color: <?php echo $stData['color']; ?>;"></span>
                                <?php echo htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="stat-bar-val">
                                <?php echo number_format($stData['count']); ?>
                                <span class="stat-bar-pct">(<?php echo $stData['pct']; ?>%)</span>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding: 0;">
                                <div class="stat-bar-track">
                                    <div class="stat-bar-fill" style="background-color: <?php echo $stData['color']; ?>; width: <?php echo max(5, $stData['pct']); ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Single full-width By Type Card -->
            <div class="chart-card">
                <table class="chart-head-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="chart-title">Requests by Type</td>
                        <td style="text-align: right;"><span class="chart-badge"><?php echo count($typeCounts); ?> Types</span></td>
                    </tr>
                </table>
                <?php foreach ($typeCounts as $tLabel => $tCount):
                    $tPct = $totalRecords > 0 ? round(($tCount / $totalRecords) * 100) : 0;
                    $tBarPct = round(($tCount / $maxTypeCount) * 100);
                ?>
                    <table class="stat-bar-table" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="stat-bar-name"><?php echo htmlspecialchars($tLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="stat-bar-val">
                                <?php echo number_format($tCount); ?>
                                <span class="stat-bar-pct">(<?php echo $tPct; ?>%)</span>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding: 0;">
                                <div class="stat-bar-track">
                                    <div class="stat-bar-fill" style="background: linear-gradient(90deg, #1d3a2a 0%, #c89b3c 100%); background-color: #2a5139; width: <?php echo max(6, $tBarPct); ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    </table>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
}
