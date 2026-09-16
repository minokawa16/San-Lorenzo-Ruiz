<?php
/**
 * Schedule Viewer Module - Shows Mass schedules, events, and parish calendar information.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

ensureScheduleEventsTable($conn);

$page_title = 'Parish Calendar';
$body_extra_class = 'schedule-mobile-page';
$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Schedule' => null
];

$upcoming_events = [];
$stmt = $conn->prepare("SELECT title, description, event_date, start_time, end_time, location, category, priority, color_label
                        FROM schedule_events
                        WHERE visibility = 'public'
                          AND approval_status = 'approved'
                          AND status != 'cancelled'
                          AND event_date >= CURDATE()
                        ORDER BY event_date ASC, start_time ASC
                        LIMIT 10");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $upcoming_events[] = $row;
    }
    $stmt->close();
}
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/breadcrumb.php'; ?>
<?php include '../includes/back_button.php'; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">

<style>
    .calendar-user-hero {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 20px;
    }

    .calendar-user-title {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .calendar-user-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        color: #8C6A30;
        background: #F3EFE6;
        border: 1px solid #DFD7C7;
        box-shadow: 0 2px 6px rgba(46, 58, 45, 0.05);
        font-size: 1.25rem;
    }

    .calendar-user-title h1 {
        margin: 0;
        font-family: "Playfair Display", Georgia, serif;
        font-weight: 700;
        font-size: clamp(1.5rem, 2.2vw, 2.05rem);
        color: #1E252B;
        line-height: 1.2;
    }

    .calendar-user-title p {
        margin: 4px 0 0;
        color: #5F6672;
        font-size: 0.92rem;
        line-height: 1.4;
    }

    .btn-parish-gold {
        background: #C89B3C !important;
        border-color: #A97F24 !important;
        color: #FFFFFF !important;
        font-weight: 650 !important;
        padding: 8px 18px !important;
        border-radius: 10px !important;
        box-shadow: 0 4px 12px rgba(200, 155, 60, 0.22) !important;
        transition: all 0.2s ease !important;
    }

    .btn-parish-gold:hover {
        background: #A97F24 !important;
        border-color: #8C6819 !important;
        transform: translateY(-1px) !important;
        box-shadow: 0 6px 16px rgba(200, 155, 60, 0.3) !important;
    }

    /* Redesigned Filter Toolbar Card */
    .calendar-toolbar-card {
        background: #FFFFFF;
        border: 1px solid #EBE4D8;
        border-radius: 16px;
        padding: 20px 22px;
        margin-bottom: 24px;
        box-shadow: 0 2px 10px rgba(46, 58, 45, 0.03);
    }

    .calendar-filters-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        align-items: start;
    }

    @media (max-width: 991px) {
        .calendar-filters-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
    }

    @media (max-width: 575px) {
        .calendar-filters-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }
    }

    .filter-field {
        display: flex;
        flex-direction: column;
    }

    .filter-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748B;
        letter-spacing: 0.05em;
        margin-bottom: 6px;
        text-transform: uppercase;
    }

    .filter-input-wrap {
        position: relative;
        width: 100%;
    }

    .filter-input-wrap i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748B;
        font-size: 0.88rem;
        pointer-events: none;
        z-index: 2;
    }

    .filter-control {
        width: 100%;
        height: 44px;
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        border-radius: 10px;
        font-size: 0.9rem;
        color: #1F2937;
        font-weight: 500;
        padding: 8px 14px;
        transition: all 0.18s ease;
    }

    .filter-input-wrap input.filter-control {
        padding-left: 38px;
    }

    .filter-control:hover {
        border-color: #C89B3C;
        background: #FDFBF8;
    }

    .filter-control:focus {
        background: #FFFFFF;
        border-color: #C89B3C;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
        outline: none;
    }

    .form-select.filter-control {
        background-color: #FAF7F2;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2364748B' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 14px center;
        background-size: 12px 10px;
        padding-right: 36px;
        cursor: pointer;
    }

    /* Divider & Legend Key */
    .filter-divider {
        height: 1px;
        background: #EBE4D8;
        margin: 18px 0 16px 0;
        border: none;
    }

    .calendar-legend-section {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .calendar-legend-header {
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748B;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .calendar-legend-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .legend-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 5px 13px;
        border-radius: 9999px;
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        font-size: 0.8rem;
        font-weight: 550;
        color: #374151;
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
    }

    .legend-badge:hover {
        border-color: #C89B3C;
        background: #FFFFFF;
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.12);
    }

    .legend-badge.active {
        background: #F4EAD3;
        border-color: #C89B3C;
        color: #8A681B;
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.16);
    }

    .legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    /* Full-Width Calendar Panel */
    .calendar-panel.full-width-calendar {
        background: #FFFFFF;
        border: 1px solid #E8E1D5;
        border-radius: 14px;
        padding: 20px;
        min-height: 780px;
        position: relative;
        box-shadow: 0 4px 18px rgba(46, 58, 45, 0.04);
        width: 100%;
    }

    .calendar-loading {
        position: absolute;
        inset: 16px;
        display: none;
        border-radius: 10px;
        background: linear-gradient(90deg, rgba(255,255,255,0.4), rgba(250,247,242,0.92), rgba(255,255,255,0.4));
        background-size: 220% 100%;
        animation: shimmer 1.2s linear infinite;
        z-index: 10;
    }

    .calendar-loading.active {
        display: block;
    }

    @keyframes shimmer {
        from { background-position: 220% 0; }
        to { background-position: -220% 0; }
    }

    /* FullCalendar Styling Overrides */
    .fc {
        font-family: "Inter", "Segoe UI", Arial, sans-serif;
        color: #1F2937;
        width: 100% !important;
    }

    .fc .fc-toolbar.fc-header-toolbar {
        margin-bottom: 18px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .fc .fc-toolbar-title {
        font-family: "Playfair Display", Georgia, serif;
        font-size: 1.55rem;
        font-weight: 700;
        color: #1F2937;
        letter-spacing: -0.2px;
    }

    .fc .fc-button-primary {
        background: #FFFFFF;
        border: 1px solid #E8E1D5;
        color: #2E3A2D;
        border-radius: 8px;
        padding: 6px 14px;
        font-size: 0.88rem;
        font-weight: 600;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: all 0.18s ease;
    }

    .fc .fc-button-primary:hover {
        background: #FAF7F2;
        border-color: #C89B3C;
        color: #A97F24;
    }

    .fc .fc-button-primary:not(:disabled).fc-button-active,
    .fc .fc-button-primary:not(:disabled):active {
        background: #C89B3C !important;
        border-color: #A97F24 !important;
        color: #FFFFFF !important;
        box-shadow: 0 2px 8px rgba(200, 155, 60, 0.28) !important;
    }

    .fc-theme-standard td,
    .fc-theme-standard th,
    .fc-theme-standard .fc-scrollgrid {
        border-color: #E8E1D5;
    }

    .fc-col-header-cell {
        background: #FAF7F2;
        padding: 10px 0;
        font-size: 0.88rem;
        font-weight: 700;
        color: #2E3A2D;
    }

    .fc-col-header-cell-cushion {
        color: #2E3A2D !important;
        text-decoration: none !important;
    }

    .fc .fc-daygrid-day-frame {
        min-height: 110px;
        padding: 6px;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }

    .fc-daygrid-day-top {
        flex-direction: row;
        margin-bottom: 4px;
    }

    .fc-daygrid-day-number {
        font-size: 0.9rem;
        font-weight: 700;
        color: #374151;
        padding: 3px 6px;
        text-decoration: none !important;
    }

    .fc .fc-day-today {
        background: rgba(200, 155, 60, 0.08) !important;
    }

    .fc .fc-day-today .fc-daygrid-day-number {
        background: #C89B3C !important;
        color: #FFFFFF !important;
        border-radius: 6px;
    }

    .fc-event {
        border: 0;
        border-radius: 6px;
        padding: 2px 4px;
        background: transparent !important;
        box-shadow: none;
        cursor: pointer;
    }

    .user-calendar-event {
        display: flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
        padding: 3px 6px;
        border-radius: 6px;
        background: #FFFFFF;
        border: 1px solid rgba(46, 58, 45, 0.14);
        box-shadow: 0 1px 4px rgba(0,0,0,0.03);
        color: #1F2937;
        font-size: 0.78rem;
        font-weight: 650;
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .user-calendar-event:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0,0,0,0.08);
        border-color: #C89B3C;
    }

    .user-calendar-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex: 0 0 8px;
        background: var(--event-dot, #C89B3C);
    }

    .user-calendar-time {
        flex: 0 0 auto;
        font-weight: 700;
        color: #6B7280;
        font-size: 0.72rem;
    }

    .user-calendar-title {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #1F2937;
    }

    /* Upcoming Section Below Calendar */
    .calendar-upcoming-section {
        background: #FFFFFF;
        border: 1px solid #E8E1D5;
        border-radius: 14px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(46, 58, 45, 0.04);
    }

    .upcoming-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .upcoming-section-header h2 {
        margin: 0;
        font-family: "Playfair Display", Georgia, serif;
        font-size: 1.25rem;
        font-weight: 700;
        color: #1F2937;
    }

    .bg-parish-gold {
        background: #C89B3C !important;
        color: #FFFFFF !important;
        font-weight: 600;
        font-size: 0.8rem;
        padding: 5px 10px;
        border-radius: 8px;
    }

    .upcoming-events-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 14px;
    }

    .event-card {
        border: 1px solid #E8E1D5;
        border-left: 4px solid var(--event-color, #C89B3C);
        border-radius: 10px;
        padding: 14px;
        background: #FAF7F2;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .event-card:hover {
        transform: translateY(-2px);
        background: #FFFFFF;
        border-color: #C89B3C;
        box-shadow: 0 6px 16px rgba(46, 58, 45, 0.08);
    }

    .event-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
    }

    .event-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1F2937;
        line-height: 1.3;
    }

    .event-category-badge {
        font-size: 0.72rem;
        font-weight: 700;
        color: #8A733B;
        background: rgba(200, 155, 60, 0.14);
        padding: 2px 7px;
        border-radius: 6px;
        white-space: nowrap;
    }

    .event-card-body {
        display: flex;
        flex-direction: column;
        gap: 3px;
        font-size: 0.84rem;
        color: #6B7280;
    }

    .event-card-body span,
    .event-card-body small {
        display: flex;
        align-items: center;
    }

    .client-empty {
        padding: 24px;
        text-align: center;
        color: #6B7280;
        border: 1px dashed #E8E1D5;
        border-radius: 10px;
        background: #FAF7F2;
    }

    @media (max-width: 767px) {
        .calendar-user-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .calendar-user-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .calendar-toolbar-controls {
            width: 100%;
        }

        .calendar-toolbar-controls .form-select,
        .calendar-toolbar-controls .mini-month {
            width: 100%;
        }

        .calendar-panel.full-width-calendar {
            min-height: 560px;
            padding: 12px;
        }

        .fc .fc-toolbar {
            align-items: flex-start;
            flex-direction: column;
            gap: 8px;
        }
    }
</style>

<div class="container-fluid mt-4">
    <div class="calendar-user-hero">
        <div class="calendar-user-title">
            <div class="calendar-user-icon"><i class="far fa-calendar-alt"></i></div>
            <div>
                <h1>Parish Calendar</h1>
                <p>View public parish schedules, sacraments, blessings, announcements, and feast day events clearly.</p>
            </div>
        </div>
    </div>

    <!-- Desktop Filter Toolbar (Full-Width Top Bar) -->
    <div class="calendar-toolbar-card">
        <div class="calendar-filters-grid">
            <div class="filter-field">
                <label for="search" class="filter-label">SEARCH</label>
                <div class="filter-input-wrap">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="search" class="form-control filter-control" id="search" placeholder="Search parish schedules...">
                </div>
            </div>
            <div class="filter-field">
                <label for="month" class="filter-label">MONTH</label>
                <div class="filter-input-wrap">
                    <i class="far fa-calendar"></i>
                    <input type="month" class="form-control filter-control" id="month" value="<?php echo date('Y-m'); ?>" title="Jump to month">
                </div>
            </div>
            <div class="filter-field">
                <label for="category" class="filter-label">CATEGORY</label>
                <div class="filter-input-wrap">
                    <select class="form-select filter-control" id="category">
                        <option value="all">All Categories</option>
                        <option value="mass">Mass / Public Schedule</option>
                        <option value="event">Parish Event</option>
                        <option value="monthly_mass">Monthly Mass</option>
                        <option value="sacramental">Sacramental Services</option>
                        <option value="patronal_fiesta">Patronal Fiesta</option>
                        <option value="blessing">Blessing</option>
                        <option value="reservation">Approved Bookings</option>
                        <option value="announcement">Announcement</option>
                        <option value="meeting">Meetings</option>
                    </select>
                </div>
            </div>
            <div class="filter-field">
                <label for="status" class="filter-label">STATUS</label>
                <div class="filter-input-wrap">
                    <select class="form-select filter-control" id="status">
                        <option value="all">All Statuses</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="finished">Finished</option>
                    </select>
                </div>
            </div>
        </div>

        <hr class="filter-divider">

        <!-- Category Legend Key Below Divider -->
        <div class="calendar-legend-section">
            <div class="calendar-legend-header">CATEGORIES</div>
            <div class="calendar-legend-bar">
                <span class="legend-badge" data-category="mass"><span class="legend-dot" style="background:#C89B3C"></span> Mass / Public Schedule</span>
                <span class="legend-badge" data-category="event"><span class="legend-dot" style="background:#1F2937"></span> Parish Event</span>
                <span class="legend-badge" data-category="monthly_mass"><span class="legend-dot" style="background:#0F766E"></span> Monthly Mass</span>
                <span class="legend-badge" data-category="sacramental"><span class="legend-dot" style="background:#7C3AED"></span> Sacramental Services</span>
                <span class="legend-badge" data-category="patronal_fiesta"><span class="legend-dot" style="background:#C026D3"></span> Patronal Fiesta</span>
                <span class="legend-badge" data-category="blessing"><span class="legend-dot" style="background:#D97706"></span> Blessing</span>
                <span class="legend-badge" data-category="announcement"><span class="legend-dot" style="background:#2563EB"></span> Announcement</span>
            </div>
        </div>
    </div>

    <!-- Main Full-Width Calendar Panel -->
    <section class="calendar-panel full-width-calendar">
        <div class="calendar-loading" id="calendarLoading"></div>
        <div id="userCalendar"></div>
        <div class="text-muted small mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2" id="calendarLiveStatus">
            <span><i class="fas fa-rotate"></i> Live calendar updates every 10 seconds.</span>
            <span><i class="fas fa-circle-check text-success"></i> Synchronized with parish schedule registry</span>
        </div>
    </section>

    <!-- Upcoming Public Schedules Section Below Calendar -->
    <section class="calendar-upcoming-section mt-4">
        <div class="upcoming-section-header">
            <h2><i class="fas fa-clock"></i> Upcoming Public Schedules</h2>
            <span class="badge bg-parish-gold"><?php echo count($upcoming_events); ?> Schedules</span>
        </div>
        <div class="upcoming-events-grid">
            <?php if (!empty($upcoming_events)): ?>
                <?php foreach ($upcoming_events as $event): ?>
                    <button type="button" class="event-card text-start" style="--event-color: <?php echo e($event['color_label'] ?: '#C89B3C'); ?>"
                            data-title="<?php echo e($event['title']); ?>"
                            data-date="<?php echo e(formatDate($event['event_date'])); ?>"
                            data-time="<?php echo e(formatTime($event['start_time'])); ?><?php echo !empty($event['end_time']) ? ' - ' . e(formatTime($event['end_time'])) : ''; ?>"
                            data-location="<?php echo e($event['location'] ?: 'San Lorenzo Ruiz Parish'); ?>"
                            data-category="<?php echo e(ucfirst(str_replace('_', ' ', $event['category']))); ?>"
                            data-description="<?php echo e($event['description']); ?>">
                        <div class="event-card-header">
                            <strong class="event-card-title"><?php echo e($event['title']); ?></strong>
                            <span class="event-category-badge"><?php echo e(ucfirst(str_replace('_', ' ', $event['category']))); ?></span>
                        </div>
                        <div class="event-card-body">
                            <span class="event-time"><i class="fas fa-calendar-day me-1"></i> <?php echo e(formatDate($event['event_date'])); ?> at <?php echo e(formatTime($event['start_time'])); ?></span>
                            <small class="event-location"><i class="fas fa-location-dot me-1"></i> <?php echo e($event['location'] ?: 'San Lorenzo Ruiz Parish'); ?></small>
                        </div>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center text-muted py-4 w-100 client-empty">
                    <i class="fas fa-calendar-plus fa-2x mb-2"></i>
                    <p class="mb-0">No upcoming public schedules published yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="modal fade" id="eventDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsTitle">Schedule Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-parish-gold" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
const apiUrl = '../api/calendar-events.php';
const loading = document.getElementById('calendarLoading');
let calendar;
let detailsModal;
let selectedReminder = null;
let searchTimer;

function getFilterEl(id, altId) {
    return document.getElementById(id) || (altId ? document.getElementById(altId) : null);
}

function filters() {
    const params = new URLSearchParams();
    const searchEl = getFilterEl('search', 'calendarSearch');
    const categoryEl = getFilterEl('category', 'categoryFilter');
    const statusEl = getFilterEl('status', 'statusFilter');
    const q = searchEl ? searchEl.value.trim() : '';
    const category = categoryEl ? categoryEl.value : 'all';
    const status = statusEl ? statusEl.value : 'all';
    if (q) params.set('q', q);
    if (category !== 'all') params.set('category', category);
    if (status !== 'all') params.set('status', status);
    return params;
}

function syncLegendPills() {
    const categoryEl = getFilterEl('category', 'categoryFilter');
    const currentVal = categoryEl ? categoryEl.value : 'all';
    document.querySelectorAll('.legend-badge[data-category]').forEach(pill => {
        if (pill.dataset.category === currentVal) {
            pill.classList.add('active');
        } else {
            pill.classList.remove('active');
        }
    });
}

function eventLabel(value) {
    return String(value || 'Schedule').replace(/_/g, ' ').replace(/\b\w/g, function(char) {
        return char.toUpperCase();
    });
}

function showDetails(data) {
    selectedReminder = data;
    document.getElementById('detailsTitle').textContent = data.title;
    document.getElementById('detailsBody').innerHTML = `
        <div class="d-grid gap-2">
            <div><strong>When:</strong> ${data.when}</div>
            <div><strong>Location:</strong> ${data.location || 'San Lorenzo Ruiz Parish'}</div>
            <div><strong>Category:</strong> <span class="badge bg-secondary">${data.category || 'Schedule'}</span></div>
            <div><strong>Status:</strong> <span class="badge bg-success">${data.status || 'Upcoming'}</span></div>
            ${data.description ? `<div class="mt-2 p-2 bg-light rounded"><strong>Description:</strong><p class="mb-0 mt-1">${data.description}</p></div>` : ''}
        </div>`;
    detailsModal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    const isMobileCalendar = window.matchMedia('(max-width: 767px)').matches;
    detailsModal = new bootstrap.Modal(document.getElementById('eventDetailsModal'));
    calendar = new FullCalendar.Calendar(document.getElementById('userCalendar'), {
        initialView: 'dayGridMonth',
        height: 'auto',
        nowIndicator: true,
        dayMaxEvents: isMobileCalendar ? 2 : 6,
        eventTimeFormat: {
            hour: 'numeric',
            minute: '2-digit',
            meridiem: 'short'
        },
        datesSet: function(dateInfo) {
            const d = dateInfo.view.currentStart;
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const monthInput = getFilterEl('month', 'miniMonth');
            if (monthInput) {
                monthInput.value = `${yyyy}-${mm}`;
            }
        },
        headerToolbar: isMobileCalendar ? {
            left: 'prev',
            center: 'title',
            right: 'next'
        } : {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        footerToolbar: isMobileCalendar ? {
            left: 'today',
            center: '',
            right: 'dayGridMonth,listWeek'
        } : false,
        buttonText: {
            today: 'Today',
            month: 'Month',
            week: 'Week',
            day: 'Day',
            list: 'Agenda'
        },
        eventContent: function(arg) {
            const timeText = arg.timeText ? `<span class="user-calendar-time">${arg.timeText}</span>` : '';
            const color = arg.event.backgroundColor || arg.event.borderColor || '#C89B3C';
            return {
                html: `<span class="user-calendar-event"><span class="user-calendar-dot" style="--event-dot:${color}"></span>${timeText}<span class="user-calendar-title">${arg.event.title}</span></span>`
            };
        },
        events: function(info, successCallback, failureCallback) {
            const params = filters();
            params.set('start', info.startStr.slice(0, 10));
            params.set('end', info.endStr.slice(0, 10));
            fetch(apiUrl + '?' + params.toString())
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Calendar request failed');
                    }
                    return response.json();
                })
                .then(events => {
                    successCallback(Array.isArray(events) ? events : []);
                    const liveStatus = document.getElementById('calendarLiveStatus');
                    if (liveStatus) {
                        liveStatus.firstElementChild.innerHTML = '<i class="fas fa-check-circle text-success"></i> Updated ' + new Date().toLocaleTimeString();
                    }
                })
                .catch(error => {
                    const liveStatus = document.getElementById('calendarLiveStatus');
                    if (liveStatus) {
                        liveStatus.firstElementChild.innerHTML = '<i class="fas fa-triangle-exclamation text-danger"></i> Unable to load calendar. Please refresh.';
                    }
                    failureCallback(error);
                });
        },
        loading: function(isLoading) {
            if (loading) loading.classList.toggle('active', isLoading);
        },
        eventClick: function(info) {
            const props = info.event.extendedProps || {};
            const when = info.event.start.toLocaleString() + (info.event.end ? ' - ' + info.event.end.toLocaleTimeString() : '');
            showDetails({
                title: info.event.title,
                when,
                location: props.location || 'San Lorenzo Ruiz Parish',
                category: eventLabel(props.category || 'Schedule'),
                status: eventLabel(props.status || 'upcoming'),
                description: props.description || '',
                start: info.event.start
            });
        }
    });
    calendar.render();
    setInterval(() => calendar.refetchEvents(), 15000);
    window.addEventListener('focus', () => calendar.refetchEvents());
});

const monthInput = getFilterEl('month', 'miniMonth');
if (monthInput) {
    monthInput.addEventListener('change', function() {
        if (this.value && calendar) {
            calendar.gotoDate(this.value + '-01');
        }
    });
}

['category', 'categoryFilter', 'status', 'statusFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('change', () => {
            syncLegendPills();
            if (calendar) calendar.refetchEvents();
        });
    }
});

const searchInput = getFilterEl('search', 'calendarSearch');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            if (calendar) calendar.refetchEvents();
        }, 280);
    });
}

document.querySelectorAll('.legend-badge[data-category]').forEach(pill => {
    pill.addEventListener('click', function() {
        const categoryEl = getFilterEl('category', 'categoryFilter');
        if (!categoryEl) return;
        const targetCat = this.dataset.category;
        if (categoryEl.value === targetCat) {
            categoryEl.value = 'all';
        } else {
            categoryEl.value = targetCat;
        }
        syncLegendPills();
        if (calendar) calendar.refetchEvents();
    });
});

document.querySelectorAll('.event-card').forEach(card => {
    card.addEventListener('click', function() {
        showDetails({
            title: this.dataset.title,
            when: `${this.dataset.date} ${this.dataset.time}`,
            location: this.dataset.location,
            category: this.dataset.category || 'Upcoming',
            status: 'Upcoming',
            description: this.dataset.description,
            start: new Date()
        });
    });
});
</script>

<?php include '../templates/footer.php'; ?>
