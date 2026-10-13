<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/ReportPdfGenerator.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$meta = [
    'parishioners_total' => 4,
    'sacraments_total' => 13,
    'total_requests' => 37,
    'events_month' => 3,
    'operational_summary' => [
        'range' => 'month',
        'range_label' => 'This Month',
        'from' => '',
        'to' => '',
        'updated_at' => 'Oct 13, 2026 11:45 PM',
        'total_certificates' => 12,
        'certs_breakdown' => [
            'baptism' => 7,
            'confirmation' => 3,
            'marriage' => 2
        ],
        'blessings_completed' => 5,
        'blessings_total' => 9,
        'blessings_pending' => 2,
        'blessings_approved' => 1,
        'blessings_cancelled' => 1,
        'blessings_by_type' => [
            'house_blessing' => ['total' => 7],
            'event_blessing' => ['total' => 1],
            'office_blessing' => ['total' => 1]
        ],
        'services_total' => 15,
        'services_breakdown' => []
    ]
];

$html = ReportPdfGenerator::buildHtml(
    'turnaround',
    'Turnaround Report',
    [],
    [
        'columns' => ['ref' => 'Reference', 'type' => 'Type', 'status' => 'Status', 'requester' => 'Requester', 'date' => 'Date'],
        'rows' => [
            ['ref' => 'REQ-001', 'type' => 'Baptism Certificate', 'status' => 'completed', 'requester' => 'Juan Dela Cruz', 'date' => '2026-10-01'],
            ['ref' => 'REQ-002', 'type' => 'House Blessing', 'status' => 'pending', 'requester' => 'Maria Clara', 'date' => '2026-10-02'],
        ]
    ],
    'Admin',
    'Parish Operational Statistics',
    [],
    $meta,
    true
);

echo "HTML length: " . strlen($html) . PHP_EOL;

assert(strpos($html, 'Operational Summary') !== false, 'Operational Summary header missing');
assert(strpos($html, 'Total Certificates Created') !== false, 'Total Certificates missing');
assert(strpos($html, 'Blessings Completed') !== false, 'Blessings Completed missing');
assert(strpos($html, 'Sacramental Services') !== false, 'Sacramental Services missing');
assert(strpos($html, 'Certificates Breakdown') !== false, 'Certificates Breakdown missing');
assert(strpos($html, 'Blessings Breakdown') !== false, 'Blessings Breakdown missing');
assert(strpos($html, 'Requests by Blessing Type') !== false, 'Requests by Blessing Type missing');
assert(strpos($html, 'Range: This Month') !== false, 'Range line missing');

// Verify order in HTML body: KPI summary -> Operational Summary -> Table
$posKpi = strpos($html, '<table class="kpi-summary-table"');
$posOp = strpos($html, '<div class="pdf-op-section"');
$posTable = strpos($html, '<table class="data-table"');
assert($posKpi !== false && $posOp !== false && $posTable !== false, 'Elements must exist in body');
assert($posKpi < $posOp, 'KPI should precede Operational Summary');
assert($posOp < $posTable, 'Operational Summary should precede table');

echo "HTML assertion tests: PASSED" . PHP_EOL;

// Test Dompdf compilation
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$canvas = $dompdf->getCanvas();
$pageCount = $canvas->get_page_count();
echo "Total PDF Pages generated: $pageCount" . PHP_EOL;

// Test Empty State ("No data for this range")
$metaEmpty = [
    'parishioners_total' => 4,
    'sacraments_total' => 13,
    'total_requests' => 37,
    'events_month' => 3,
    'operational_summary' => [
        'range' => 'custom',
        'range_label' => 'Nov 01, 2026 – Nov 15, 2026',
        'from' => '2026-11-01',
        'to' => '2026-11-15',
        'updated_at' => 'Nov 15, 2026 12:00 PM',
        'total_certificates' => 0,
        'certs_breakdown' => [],
        'blessings_completed' => 0,
        'blessings_total' => 0,
        'blessings_pending' => 0,
        'blessings_approved' => 0,
        'blessings_cancelled' => 0,
        'blessings_by_type' => [],
        'services_total' => 0,
        'services_breakdown' => []
    ]
];

$htmlEmpty = ReportPdfGenerator::buildHtml('turnaround', 'Turnaround Report', [], ['columns' => ['id' => 'ID'], 'rows' => []], 'Admin', null, [], $metaEmpty, true);
$noDataCount = substr_count($htmlEmpty, 'No data for this range');
assert($noDataCount === 3, "Expected 3 'No data for this range' notices, got $noDataCount");
echo "Empty state assertions: PASSED (found $noDataCount 'No data for this range' notices)" . PHP_EOL;

echo "ALL OPERATIONAL SUMMARY PDF TESTS COMPLETED SUCCESSFULLY!" . PHP_EOL;
