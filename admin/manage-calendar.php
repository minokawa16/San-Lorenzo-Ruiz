<?php
/**
 * Calendar Management Module - Allows administrators to publish schedules, Masses, and parish events.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('calendar.manage');
ensureScheduleEventsTable($conn);

$page_title = 'Calendar & Scheduling';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Schedule Calendar' => null
];

include '../templates/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
<style>
    :root {
        --calendar-gold: #c89b3c;
        --calendar-gold-dark: #a77f2a;
        --calendar-border: #d8d6cc;
        --calendar-card: #ffffff;
        --calendar-text: #1e293b;
        --calendar-muted: #64748b;
    }

    .calendar-shell {
        width: 100%;
        max-width: 100%;
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    /* 12-Column Responsive CSS Grid */
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 24px;
        align-items: start;
        width: 100%;
        margin-bottom: 24px;
        box-sizing: border-box;
    }

    /* Left Sidebar Filter Card (col-span-3 on XL, col-span-4 on LG) */
    .calendar-sidebar {
        grid-column: span 3 / span 3;
        background: #ffffff;
        border: 1px solid var(--calendar-border);
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        position: sticky;
        top: 10px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        min-width: 0;
        word-break: break-word;
    }

    .mini-month,
    .filter-select,
    .search-box input {
        border: 1px solid var(--calendar-border);
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 0.84rem;
        color: var(--calendar-text);
        background: #ffffff;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin-bottom: 12px;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .mini-month:focus,
    .filter-select:focus,
    .search-box input:focus {
        border-color: var(--calendar-gold);
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
    }

    .search-box {
        position: relative;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin-bottom: 12px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #9a9890;
        font-size: 0.8rem;
        pointer-events: none;
    }

    .search-box input {
        padding-left: 34px;
        margin-bottom: 0;
    }

    .legend {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin: 14px 0 16px;
        padding-top: 14px;
        border-top: 1px solid #f0eee6;
        width: 100%;
        box-sizing: border-box;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--calendar-muted);
        word-break: break-word;
        overflow-wrap: break-word;
    }

    .legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .smart-card {
        background: #FAF8F3;
        border: 1px solid #E8E1D5;
        border-radius: 10px;
        padding: 14px;
        margin-top: 12px;
        width: 100%;
        box-sizing: border-box;
        word-break: break-word;
        overflow-wrap: break-word;
    }

    .smart-card strong {
        display: block;
        font-size: 0.84rem;
        color: var(--calendar-text);
        margin-bottom: 4px;
    }

    .smart-card span {
        font-size: 0.78rem;
        color: var(--calendar-muted);
        line-height: 1.45;
        display: block;
    }

    /* Right Main Calendar Panel (col-span-9 on XL, col-span-8 on LG) */
    .calendar-main {
        grid-column: span 9 / span 9;
        background: #ffffff;
        border: 1px solid var(--calendar-border);
        border-radius: 12px;
        padding: 22px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        min-width: 0;
        overflow: hidden;
        position: relative;
    }

    #calendar {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
        overflow: hidden;
    }

    /* FullCalendar Responsive & Layout Overrides */
    .fc {
        font-family: inherit;
        color: var(--calendar-text);
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    .fc .fc-toolbar.fc-header-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        width: 100%;
    }

    .fc .fc-toolbar-chunk:first-child {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .fc .fc-toolbar-title {
        font-family: 'Playfair Display', Georgia, serif !important;
        font-size: 1.5rem !important;
        font-weight: 700 !important;
        color: #1e293b !important;
        margin: 0 !important;
        letter-spacing: -0.02em;
    }

    .fc .fc-button-group {
        display: inline-flex;
        gap: 4px;
    }

    .fc .fc-prev-button,
    .fc .fc-next-button {
        background: #ffffff !important;
        border: 1px solid var(--calendar-border) !important;
        color: #1e293b !important;
        border-radius: 8px !important;
        width: 34px !important;
        height: 34px !important;
        padding: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-weight: 700 !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02) !important;
        transition: all 0.15s ease !important;
    }

    .fc .fc-prev-button:hover,
    .fc .fc-next-button:hover {
        background: #FAF4E6 !important;
        border-color: var(--calendar-gold) !important;
        color: #8a6409 !important;
    }

    .fc .fc-today-button {
        background: #ffffff !important;
        border: 1px solid var(--calendar-border) !important;
        color: #1e293b !important;
        border-radius: 8px !important;
        font-weight: 600 !important;
        font-size: 0.8rem !important;
        padding: 6px 14px !important;
        height: 34px !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02) !important;
        text-transform: capitalize !important;
        transition: all 0.15s ease !important;
    }

    .fc .fc-today-button:hover {
        background: #FAF4E6 !important;
        border-color: var(--calendar-gold) !important;
        color: #8a6409 !important;
    }

    .fc .fc-today-button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* View Switcher Buttons (Month, Week, Day, Agenda) */
    .fc .fc-dayGridMonth-button,
    .fc .fc-timeGridWeek-button,
    .fc .fc-timeGridDay-button,
    .fc .fc-listWeek-button {
        background: #ffffff !important;
        border: 1px solid var(--calendar-border) !important;
        color: var(--calendar-muted) !important;
        border-radius: 8px !important;
        font-weight: 600 !important;
        font-size: 0.82rem !important;
        padding: 6px 14px !important;
        height: 34px !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02) !important;
        transition: all 0.15s ease !important;
        margin: 0 2px !important;
    }

    .fc .fc-dayGridMonth-button:hover,
    .fc .fc-timeGridWeek-button:hover,
    .fc .fc-timeGridDay-button:hover,
    .fc .fc-listWeek-button:hover {
        background: #f8f6f0 !important;
        color: #1e293b !important;
        border-color: #c4c1b5 !important;
    }

    .fc .fc-button.fc-button-active {
        background: var(--calendar-gold) !important;
        color: #1e293b !important;
        border-color: var(--calendar-gold) !important;
        font-weight: 700 !important;
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.25) !important;
    }

    /* Day Number & Column Headers */
    .fc a,
    .fc .fc-daygrid-day-number,
    .fc .fc-col-header-cell-cushion,
    .fc-theme-standard a {
        text-decoration: none !important;
        font-weight: 600 !important;
        color: #1e293b !important;
    }

    /* 7-Column Header Grid Alignment */
    .fc-scrollgrid,
    .fc-col-header,
    .fc-daygrid-body,
    .fc-scrollgrid-sync-table,
    .fc-daygrid-body table,
    .fc-col-header table {
        width: 100% !important;
        table-layout: fixed !important;
        box-sizing: border-box !important;
    }

    .fc .fc-col-header-cell {
        background: #F8F6F1 !important;
        padding: 10px 0 !important;
        font-weight: 700 !important;
        color: #64748b !important;
        text-transform: uppercase !important;
        font-size: 0.75rem !important;
        letter-spacing: 0.05em !important;
        border-color: #e5e0d5 !important;
        text-align: center !important;
    }

    .fc-theme-standard td,
    .fc-theme-standard th,
    .fc-theme-standard .fc-scrollgrid {
        border-color: #e5e0d5 !important;
    }

    .fc .fc-daygrid-day-frame {
        padding: 6px !important;
        min-height: 95px !important;
        transition: background-color 0.12s ease !important;
        box-sizing: border-box !important;
    }

    .fc .fc-daygrid-day:hover {
        background-color: #fbf9f4 !important;
    }

    .fc .fc-day-today {
        background-color: #fffdf7 !important;
    }

    .fc .fc-day-today .fc-daygrid-day-number {
        background: var(--calendar-gold) !important;
        color: #1e293b !important;
        border-radius: 50% !important;
        width: 26px !important;
        height: 26px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 0.8rem !important;
        font-weight: 700 !important;
    }

    /* Event Badges */
    .fc-event {
        border: none !important;
        border-radius: 6px !important;
        padding: 3px 8px !important;
        font-size: 0.76rem !important;
        font-weight: 600 !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06) !important;
        margin-bottom: 3px !important;
        cursor: pointer !important;
        transition: transform 0.12s ease, box-shadow 0.12s ease !important;
    }

    .fc-event:hover {
        transform: translateY(-1px) scale(1.02) !important;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12) !important;
    }

    .fc-event-title {
        font-weight: 600 !important;
        color: #ffffff !important;
    }

    /* FAB Add Button */
    .fab-add {
        position: fixed;
        right: 28px;
        bottom: 28px;
        z-index: 200;
        width: 54px;
        height: 54px;
        border-radius: 50%;
        border: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1e293b;
        background: var(--calendar-gold);
        box-shadow: 0 8px 24px rgba(200, 155, 60, 0.4);
        font-size: 1.2rem;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .fab-add:hover {
        transform: scale(1.08) translateY(-2px);
        background: #b58930;
        box-shadow: 0 12px 28px rgba(200, 155, 60, 0.5);
    }

    .calendar-loading {
        position: absolute;
        inset: 12px;
        display: none;
        border-radius: 12px;
        background: linear-gradient(90deg, rgba(255,255,255,0.35), rgba(255,255,255,0.85), rgba(255,255,255,0.35));
        background-size: 220% 100%;
        animation: shimmer 1.2s linear infinite;
        pointer-events: none;
        z-index: 30;
    }

    .calendar-loading.active {
        display: block;
    }

    @keyframes shimmer {
        from { background-position: 220% 0; }
        to { background-position: -220% 0; }
    }

    .modal-content {
        border: 1px solid var(--calendar-border);
        border-radius: 14px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }

    .modal-header {
        border-bottom: 1px solid var(--calendar-border);
        background: #ffffff;
        color: var(--calendar-text);
        border-radius: 14px 14px 0 0;
    }

    .modal-body,
    .modal-footer {
        background: #ffffff;
        color: var(--calendar-text);
    }

    .color-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .color-swatch {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: 3px solid transparent;
        cursor: pointer;
    }

    .color-swatch.active {
        border-color: #1e293b;
    }

    /* Responsive Breakpoints */
    @media (max-width: 1200px) {
        .calendar-sidebar {
            grid-column: span 4 / span 4;
        }
        .calendar-main {
            grid-column: span 8 / span 8;
        }
    }

    @media (max-width: 991px) {
        .calendar-grid {
            grid-template-columns: 1fr;
            gap: 18px;
        }
        .calendar-sidebar,
        .calendar-main {
            grid-column: span 1 / span 1;
            position: static;
            width: 100%;
        }
    }
</style>
<div class="parish-toast-container" id="parishToastContainer" aria-live="polite" aria-atomic="true"></div>
    <div class="calendar-shell">
        <?php
        $page_header_title = 'Calendar & Scheduling';
        $page_header_subtitle = 'Manage parish events, tasks, reservations, meetings, and sacramental schedules.';
        $page_header_icon = 'fa-calendar-days';
        $show_back_button = true;
        $back_button_url = BASE_URL . 'admin/dashboard.php';
        include '../includes/page_header.php';
        ?>

        <div class="calendar-grid">
            <aside class="calendar-sidebar">
                <input class="mini-month" type="month" id="miniMonth" value="<?php echo date('Y-m'); ?>">

                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="search" id="calendarSearch" placeholder="Search schedules">
                </div>

                <select class="filter-select" id="categoryFilter">
                    <option value="all">All categories</option>
                    <option value="event">Events</option>
                    <option value="mass">Mass / Public Schedule</option>
                    <option value="monthly_mass">Monthly Mass</option>
                    <option value="sacramental">Sacramental Services</option>
                    <option value="patronal_fiesta">Patronal Fiesta</option>
                    <option value="meeting">Meetings</option>
                    <option value="task">Tasks</option>
                    <option value="blessing">Blessings</option>
                    <option value="reservation">Reservations</option>
                    <option value="announcement">Announcements</option>
                </select>

                <select class="filter-select" id="statusFilter">
                    <option value="all">All statuses</option>
                    <option value="upcoming">Upcoming</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="finished">Finished</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <div class="legend" aria-label="Calendar legend">
                    <div class="legend-item"><span class="legend-dot" style="background:#1a73e8"></span> Parish Event</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#34a853"></span> Mass / Public Schedule</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#0f9d58"></span> Monthly Mass</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#a142f4"></span> Sacramental Services</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#c026d3"></span> Patronal Fiesta</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#fbbc04"></span> Announcement</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#d7ad43"></span> Blessing</div>
                </div>

                <div class="smart-card">
                    <strong><i class="fas fa-bell"></i> Smart reminders</strong>
                    <span>Calendar entries can create in-app reminders for parishioners when public notifications are enabled. Email and SMS flags are stored for future gateway integration.</span>
                </div>
            </aside>

            <section class="calendar-main">
                <div class="calendar-loading" id="calendarLoading"></div>
                <div id="calendar"></div>
            </section>
        </div>
    </div>

<button class="fab-add" type="button" id="fabAdd" aria-label="Add event">
    <i class="fas fa-plus"></i>
</button>

<div class="toast-stack" id="toastStack"></div>

<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="eventForm">
            <div class="modal-header">
                <h5 class="modal-title" id="eventModalTitle">Add Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="schedule_id">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="title">Title</label>
                        <input type="text" class="form-control" id="title" maxlength="200" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="category">Category</label>
                        <select class="form-select" id="category">
                            <option value="event">Event</option>
                            <option value="mass">Mass schedule</option>
                            <option value="monthly_mass">Monthly Mass</option>
                            <option value="sacramental">Sacramental Services</option>
                            <option value="patronal_fiesta">Patronal Fiesta</option>
                            <option value="meeting">Meeting</option>
                            <option value="task">Task</option>
                            <option value="blessing">Blessing</option>
                            <option value="reservation">Reservation</option>
                            <option value="announcement">Announcement</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" rows="3"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="event_date">Date</label>
                        <input type="date" class="form-control" id="event_date" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="start_time">Start time</label>
                        <input type="time" class="form-control" id="start_time" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="end_time">End time</label>
                        <input type="time" class="form-control" id="end_time">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="location">Location</label>
                        <input type="text" class="form-control" id="location" maxlength="150">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="assigned_personnel">Assigned personnel / ministry</label>
                        <input type="text" class="form-control" id="assigned_personnel" maxlength="150">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="priority">Priority</label>
                        <select class="form-select" id="priority">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="recurrence_rule">Recurring</label>
                        <select class="form-select" id="recurrence_rule">
                            <option value="none">Does not repeat</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status">
                            <option value="upcoming">Upcoming</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="finished">Finished</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="visibility">Visibility</label>
                        <select class="form-select" id="visibility">
                            <option value="public">Public</option>
                            <option value="private">Private</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="approval_status">Approval</label>
                        <select class="form-select" id="approval_status">
                            <option value="approved">Approved</option>
                            <option value="pending">Pending</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="reminder_minutes">Reminder</label>
                        <select class="form-select" id="reminder_minutes">
                            <option value="0">No reminder</option>
                            <option value="15">15 minutes before</option>
                            <option value="30" selected>30 minutes before</option>
                            <option value="60">1 hour before</option>
                            <option value="1440">1 day before</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Color label</label>
                        <input type="hidden" id="color_label" value="#1a73e8">
                        <div class="color-row" id="colorRow">
                            <button type="button" class="color-swatch active" data-color="#1a73e8" style="background:#1a73e8" aria-label="Blue"></button>
                            <button type="button" class="color-swatch" data-color="#34a853" style="background:#34a853" aria-label="Green"></button>
                            <button type="button" class="color-swatch" data-color="#a142f4" style="background:#a142f4" aria-label="Purple"></button>
                            <button type="button" class="color-swatch" data-color="#fbbc04" style="background:#fbbc04" aria-label="Yellow"></button>
                            <button type="button" class="color-swatch" data-color="#ea4335" style="background:#ea4335" aria-label="Red"></button>
                            <button type="button" class="color-swatch" data-color="#00acc1" style="background:#00acc1" aria-label="Cyan"></button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="notify_email">
                            <label class="form-check-label" for="notify_email">Notify users in portal / email-ready</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="notify_sms">
                            <label class="form-check-label" for="notify_sms">SMS-ready reminder flag</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto d-none" id="deleteEventBtn"><i class="fas fa-trash"></i> Delete</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsTitle">Schedule Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
const apiUrl = '../api/calendar-events.php';
const modal = new bootstrap.Modal(document.getElementById('eventModal'));
const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
const form = document.getElementById('eventForm');
const loading = document.getElementById('calendarLoading');
let calendar;
let searchTimer;

const defaultColors = {
    event: '#1a73e8',
    mass: '#34a853',
    monthly_mass: '#0f9d58',
    sacramental: '#a142f4',
    patronal_fiesta: '#c026d3',
    meeting: '#00acc1',
    task: '#fbbc04',
    blessing: '#d7ad43',
    reservation: '#188038',
    announcement: '#fbbc04'
};

// Toast Function - Documents this helper's role in the parish management workflow.
function toast(message, type = 'success') {
    if (window.ParishNotify && typeof window.ParishNotify.show === 'function') {
        window.ParishNotify.show({message, type});
        return;
    }
    const el = document.createElement('div');
    el.className = `alert alert-${type === 'error' ? 'danger' : type} shadow-sm`;
    el.textContent = message;
    document.getElementById('toastStack').appendChild(el);
    setTimeout(() => el.remove(), 4200);
}

// To Date Input Function - Documents this helper's role in the parish management workflow.
function toDateInput(date) {
    return date.toISOString().slice(0, 10);
}

// To Time Input Function - Documents this helper's role in the parish management workflow.
function toTimeInput(date) {
    return date ? date.toTimeString().slice(0, 5) : '';
}

// Event Filters Function - Documents this helper's role in the parish management workflow.
function eventFilters() {
    const params = new URLSearchParams();
    const q = document.getElementById('calendarSearch').value.trim();
    const category = document.getElementById('categoryFilter').value;
    const status = document.getElementById('statusFilter').value;
    if (q) params.set('q', q);
    if (category !== 'all') params.set('category', category);
    if (status !== 'all') params.set('status', status);
    return params;
}

// Reset Form Function - Documents this helper's role in the parish management workflow.
function resetForm(date = new Date()) {
    form.reset();
    document.getElementById('eventModalTitle').textContent = 'Add Schedule';
    document.getElementById('schedule_id').value = '';
    document.getElementById('event_date').value = toDateInput(date);
    document.getElementById('start_time').value = '08:00';
    document.getElementById('end_time').value = '09:00';
    document.getElementById('color_label').value = '#1a73e8';
    document.getElementById('deleteEventBtn').classList.add('d-none');
    setActiveColor('#1a73e8');
}

// Set Active Color Function - Documents this helper's role in the parish management workflow.
function setActiveColor(color) {
    document.getElementById('color_label').value = color;
    document.querySelectorAll('.color-swatch').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.color === color);
    });
}

// Fill Form Function - Documents this helper's role in the parish management workflow.
function fillForm(event) {
    const props = event.extendedProps;
    document.getElementById('eventModalTitle').textContent = 'Edit Schedule';
    document.getElementById('schedule_id').value = props.schedule_id || '';
    document.getElementById('title').value = event.title || '';
    document.getElementById('description').value = props.description || '';
    document.getElementById('event_date').value = toDateInput(event.start);
    document.getElementById('start_time').value = toTimeInput(event.start);
    document.getElementById('end_time').value = toTimeInput(event.end);
    document.getElementById('location').value = props.location || '';
    document.getElementById('category').value = props.category || 'event';
    document.getElementById('priority').value = props.priority || 'normal';
    document.getElementById('recurrence_rule').value = props.recurrence_rule || 'none';
    document.getElementById('assigned_personnel').value = props.assigned_personnel || '';
    document.getElementById('visibility').value = props.visibility || 'public';
    document.getElementById('approval_status').value = props.approval_status || 'approved';
    document.getElementById('status').value = props.status || 'upcoming';
    document.getElementById('reminder_minutes').value = String(props.reminder_minutes || 30);
    document.getElementById('notify_email').checked = Number(props.notify_email) === 1;
    document.getElementById('notify_sms').checked = Number(props.notify_sms) === 1;
    setActiveColor(event.backgroundColor || '#1a73e8');
    document.getElementById('deleteEventBtn').classList.remove('d-none');
}

// Form Payload Function - Documents this helper's role in the parish management workflow.
function formPayload() {
    return {
        schedule_id: document.getElementById('schedule_id').value,
        title: document.getElementById('title').value.trim(),
        description: document.getElementById('description').value.trim(),
        event_date: document.getElementById('event_date').value,
        start_time: document.getElementById('start_time').value,
        end_time: document.getElementById('end_time').value,
        location: document.getElementById('location').value.trim(),
        category: document.getElementById('category').value,
        priority: document.getElementById('priority').value,
        color_label: document.getElementById('color_label').value,
        recurrence_rule: document.getElementById('recurrence_rule').value,
        assigned_personnel: document.getElementById('assigned_personnel').value.trim(),
        visibility: document.getElementById('visibility').value,
        approval_status: document.getElementById('approval_status').value,
        status: document.getElementById('status').value,
        reminder_minutes: document.getElementById('reminder_minutes').value,
        notify_email: document.getElementById('notify_email').checked ? 1 : 0,
        notify_sms: document.getElementById('notify_sms').checked ? 1 : 0
    };
}

async function saveEvent(payload) {
    const isEdit = Boolean(payload.schedule_id);
    const response = await fetch(apiUrl, {
        method: isEdit ? 'PUT' : 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to save schedule.');
    }
    return data;
}

// Show Details Function - Documents this helper's role in the parish management workflow.
function showDetails(event) {
    const props = event.extendedProps;
    document.getElementById('detailsTitle').textContent = event.title;
    document.getElementById('detailsBody').innerHTML = `
        <div class="d-grid gap-2">
            <div><strong>When:</strong> ${event.start.toLocaleString()}${event.end ? ' - ' + event.end.toLocaleTimeString() : ''}</div>
            <div><strong>Category:</strong> ${props.category || 'schedule'}</div>
            <div><strong>Status:</strong> ${props.status || 'upcoming'}</div>
            <div><strong>Location:</strong> ${props.location || 'Parish'}</div>
            <div><strong>Source:</strong> ${props.source_type || 'schedule'}</div>
            <p class="mb-0">${props.description || ''}</p>
        </div>`;
    detailsModal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
        height: 'auto',
        contentHeight: 'auto',
        expandRows: true,
        handleWindowResize: true,
        nowIndicator: true,
        selectable: true,
        editable: true,
        eventResizableFromStart: true,
        dayMaxEvents: 3,
        headerToolbar: {
            left: 'title prev,next today',
            center: '',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        buttonText: {
            month: 'Month',
            week: 'Week',
            day: 'Day',
            list: 'Agenda'
        },
        events: function(info, successCallback, failureCallback) {
            const params = eventFilters();
            params.set('start', info.startStr.slice(0, 10));
            params.set('end', info.endStr.slice(0, 10));
            fetch(apiUrl + '?' + params.toString())
                .then(response => response.json())
                .then(successCallback)
                .catch(failureCallback);
        },
        loading: function(isLoading) {
            loading.classList.toggle('active', isLoading);
        },
        select: function(selection) {
            resetForm(selection.start);
            modal.show();
        },
        dateClick: function(info) {
            resetForm(info.date);
        },
        eventClick: function(info) {
            if (info.event.extendedProps.read_only) {
                showDetails(info.event);
                return;
            }
            fillForm(info.event);
            modal.show();
        },
        eventDrop: updateDraggedEvent,
        eventResize: updateDraggedEvent
    });

    calendar.render();
    setInterval(() => calendar.refetchEvents(), 30000);
});

async function updateDraggedEvent(info) {
    const payload = {
        event_id: info.event.id,
        event_date: toDateInput(info.event.start),
        start_time: toTimeInput(info.event.start),
        end_time: toTimeInput(info.event.end)
    };

    try {
        await saveEvent(payload);
        toast('Schedule moved.');
    } catch (error) {
        info.revert();
        toast(error.message, 'error');
    }
}

form.addEventListener('submit', async function(e) {
    e.preventDefault();
    try {
        const data = await saveEvent(formPayload());
        modal.hide();
        calendar.refetchEvents();
        toast(data.message || 'Schedule saved.');
    } catch (error) {
        toast(error.message, 'error');
    }
});

document.getElementById('deleteEventBtn').addEventListener('click', async function() {
    const id = document.getElementById('schedule_id').value;
    if (!id || !confirm('Delete this schedule?')) {
        return;
    }

    try {
        const response = await fetch(apiUrl, {
            method: 'DELETE',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({schedule_id: id})
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to delete schedule.');
        }
        modal.hide();
        calendar.refetchEvents();
        toast('Schedule deleted.');
    } catch (error) {
        toast(error.message, 'error');
    }
});

document.getElementById('fabAdd').addEventListener('click', function() {
    resetForm(new Date());
    modal.show();
});

document.getElementById('miniMonth').addEventListener('change', function() {
    calendar.gotoDate(this.value + '-01');
});

['categoryFilter', 'statusFilter'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => calendar.refetchEvents());
});

document.getElementById('calendarSearch').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => calendar.refetchEvents(), 280);
});

document.getElementById('category').addEventListener('change', function() {
    setActiveColor(defaultColors[this.value] || '#1a73e8');
});

document.querySelectorAll('.color-swatch').forEach(btn => {
    btn.addEventListener('click', () => setActiveColor(btn.dataset.color));
});
</script>
</div>
<?php include '../templates/footer.php'; ?>
