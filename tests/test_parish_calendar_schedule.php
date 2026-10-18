<?php
/**
 * Test Suite for Parish Calendar & Schedule Popup Modal & Backend
 */
define('CLI_TEST_RUNNING', true);

$passed = 0;
$failed = 0;

function assertCondition($desc, $cond) {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $passed++;
    } else {
        echo "  [FAIL] $desc\n";
        $failed++;
    }
}

echo "=== Testing Parish Calendar API Helpers ===\n";

require_once __DIR__ . '/../api/calendar-events.php';

// 1. Service Type Detection
assertCondition('Detect Baptism from title', detectCalendarServiceType('Baptism Service - Juan Dela Cruz') === 'Baptism');
assertCondition('Detect Mass from title', detectCalendarServiceType('Sunday Mass 6:30 AM') === 'Mass');
assertCondition('Detect Blessing from title', detectCalendarServiceType('House Blessing - Maria Santos') === 'Blessing');
assertCondition('Detect Wedding from title', detectCalendarServiceType('Wedding Reservation - John & Jane') === 'Wedding');
assertCondition('Detect Funeral Mass from title', detectCalendarServiceType('Funeral Mass - Late Pedro') === 'Funeral Mass');
assertCondition('Detect Confirmation from title', detectCalendarServiceType('Confirmation Service - Batch 2026') === 'Confirmation');

// 2. Sanitization (Privacy Protection: strip requester personal names)
$cleanBaptism = sanitizePublicServiceName('Baptism Service - Juan Dela Cruz', 'Baptism');
assertCondition('Sanitize Baptism title strips requester name', $cleanBaptism === 'Baptism' && !str_contains($cleanBaptism, 'Juan'));

$cleanBlessing = sanitizePublicServiceName('House Blessing - Maria Santos', 'Blessing');
assertCondition('Sanitize Blessing title strips requester name', $cleanBlessing === 'House Blessing' && !str_contains($cleanBlessing, 'Santos'));

$cleanWedding = sanitizePublicServiceName('Wedding Reservation - Romeo and Juliet', 'Wedding');
assertCondition('Sanitize Wedding title strips requester name', $cleanWedding === 'Wedding' && !str_contains($cleanWedding, 'Romeo'));

$cleanFuneral = sanitizePublicServiceName('Funeral Mass - Pedro Penduko', 'Funeral Mass');
assertCondition('Sanitize Funeral title strips requester name', $cleanFuneral === 'Funeral Mass' && !str_contains($cleanFuneral, 'Pedro'));

$cleanConfirmation = sanitizePublicServiceName('Confirmation Service - Ana Cruz', 'Confirmation');
assertCondition('Sanitize Confirmation title strips requester name', $cleanConfirmation === 'Confirmation' && !str_contains($cleanConfirmation, 'Cruz'));

$cleanMass = sanitizePublicServiceName('Sunday Mass', 'Mass');
assertCondition('Keep Sunday Mass clean', $cleanMass === 'Sunday Mass');

// 3. buildScheduleEvent for public parishioner (not editable)
$mockRow = [
    'schedule_id' => 101,
    'title' => 'Baptism Service - Private Person Name',
    'description' => 'Contact: 09171234567 for Baptism',
    'event_date' => '2026-10-25',
    'start_time' => '08:00:00',
    'end_time' => '09:00:00',
    'location' => 'Main Parish Altar',
    'category' => 'sacramental',
    'priority' => 'normal',
    'color_label' => '#0284c7',
    'recurrence_rule' => 'none',
    'assigned_personnel' => 'Rev. Fr. Priest',
    'visibility' => 'public',
    'approval_status' => 'approved',
    'status' => 'upcoming',
    'reminder_minutes' => 30,
    'notify_email' => 0,
    'notify_sms' => 0
];

$publicEv = buildScheduleEvent($mockRow, null, false);
assertCondition('buildScheduleEvent hides requester name in title', !str_contains($publicEv['title'], 'Private Person Name'));
assertCondition('buildScheduleEvent has formatted time', $publicEv['time_formatted'] === '8:00 AM');
assertCondition('buildScheduleEvent has service_type', $publicEv['service_type'] === 'Baptism');
assertCondition('buildScheduleEvent has service_name', $publicEv['service_name'] === 'Baptism');
assertCondition('buildScheduleEvent hides private details in description', !str_contains($publicEv['extendedProps']['description'], '09171234567'));

// 4. Test Template Content
echo "\n=== Testing Parish Calendar Modal Template ===\n";
$tpl = file_get_contents(__DIR__ . '/../templates/parish-calendar-modal.php');

assertCondition('Modal has title "Parish Calendar & Schedule"', str_contains($tpl, 'Parish Calendar &amp; Schedule'));
assertCondition('Header has text-white class on title', str_contains($tpl, 'modal-title mb-0 fw-bold text-white'));
assertCondition('Header has text-white class on subtitle', str_contains($tpl, 'parish-calendar-modal-sub text-white'));
assertCondition('CSS enforces pure white on header title', str_contains($tpl, '.parish-calendar-modal-header .modal-title') && str_contains($tpl, 'color: #FFFFFF !important;'));
assertCondition('Date cells taller ~96px on desktop', str_contains($tpl, 'min-height: 96px !important;') && str_contains($tpl, 'height: 96px !important;'));
assertCondition('Format "<time> · <service>" with bold time', str_contains($tpl, '<strong class="chip-time">') && str_contains($tpl, '<span class="chip-service">'));
assertCondition('Shows "+N more" badge when > 2', str_contains($tpl, 'parish-cal-more') && str_contains($tpl, 'moreCount'));
assertCondition('Color codes Baptism (sky/blue)', str_contains($tpl, 'service-chip-baptism') && str_contains($tpl, '#0284c7'));
assertCondition('Color codes Mass (amber/gold)', str_contains($tpl, 'service-chip-mass') && str_contains($tpl, '#d97706'));
assertCondition('Color codes Blessing (emerald/green)', str_contains($tpl, 'service-chip-blessing') && str_contains($tpl, '#059669'));
assertCondition('Color codes Wedding (violet/purple)', str_contains($tpl, 'service-chip-wedding') && str_contains($tpl, '#7c3aed'));
assertCondition('Color codes Funeral Mass (slate/gray)', str_contains($tpl, 'service-chip-funeral') && str_contains($tpl, '#4b5563'));
assertCondition('Color codes Confirmation (crimson/red)', str_contains($tpl, 'service-chip-confirmation') && str_contains($tpl, '#dc2626'));
assertCondition('Translucent white chips on selected dark green cell', str_contains($tpl, '.parish-cal-day.is-selected .parish-cal-chip') && str_contains($tpl, 'rgba(255, 255, 255, 0.22) !important;') && str_contains($tpl, 'color: #FFFFFF !important;'));
assertCondition('Mobile (< 600px) hides chips and shows dots', str_contains($tpl, '@media (max-width: 600px)') && str_contains($tpl, '.parish-cal-chips {') && str_contains($tpl, 'display: none !important;') && str_contains($tpl, '.parish-cal-dots-container {') && str_contains($tpl, 'display: flex !important;'));
assertCondition('Schedule panel has full date heading', str_contains($tpl, 'parish-cal-panel-heading') && str_contains($tpl, 'humanDate'));
assertCondition('Schedule panel shows "Nothing scheduled. This day is free."', str_contains($tpl, 'Nothing scheduled. This day is free.'));
assertCondition('Schedule panel lists each service with time, name, and type', str_contains($tpl, 'parish-badge-type') && str_contains($tpl, 'parish-schedule-item-name') && str_contains($tpl, 'parish-schedule-item-time'));
assertCondition('Past dates are disabled and faded', str_contains($tpl, 'is-disabled') && str_contains($tpl, 'opacity: 0.45 !important;') && str_contains($tpl, 'cursor: not-allowed !important;'));
assertCondition('Today has gold border', str_contains($tpl, 'is-today') && str_contains($tpl, 'border: 2px solid #C89B3C !important;'));
assertCondition('Use Selected Date button disabled initially', str_contains($tpl, 'id="parishCalApplyBtn" disabled'));
assertCondition('Dark mode support implemented', str_contains($tpl, '[data-theme="dark"]') && str_contains($tpl, 'body[data-theme="dark"]'));

echo "\n==========================================\n";
echo "Results: $passed Passed, $failed Failed\n";
echo "==========================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
