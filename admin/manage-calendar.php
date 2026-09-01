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
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    /* ── Two-Column Google Calendar Layout (260px Fixed Sidebar + Full-Width Grid) ── */
    .calendar-layout,
    .calendar-grid {
        display: grid !important;
        grid-template-columns: 260px minmax(0, 1fr) !important;
        gap: 20px !important;
        align-items: start !important;
        width: 100% !important;
        max-width: 100% !important;
        margin-bottom: 24px !important;
        box-sizing: border-box !important;
    }

    /* Left Sidebar Filter Card: 260px width */
    .calendar-sidebar {
        width: 260px !important;
        min-width: 260px !important;
        max-width: 260px !important;
        background: #ffffff !important;
        border: 1px solid var(--calendar-border) !important;
        border-radius: 12px !important;
        padding: 18px !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02) !important;
        position: sticky !important;
        top: 10px !important;
        box-sizing: border-box !important;
        word-break: break-word !important;
        flex-shrink: 0 !important;
    }

    .mini-month,
    .filter-select,
    .search-box input {
        border: 1px solid var(--calendar-border) !important;
        border-radius: 8px !important;
        padding: 8px 12px !important;
        font-size: 0.84rem !important;
        color: var(--calendar-text) !important;
        background: #ffffff !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        margin-bottom: 12px !important;
        outline: none !important;
        transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
    }

    .mini-month:focus,
    .filter-select:focus,
    .search-box input:focus {
        border-color: var(--calendar-gold) !important;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15) !important;
    }

    .search-box {
        position: relative !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        margin-bottom: 12px !important;
    }

    .search-box i {
        position: absolute !important;
        left: 12px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        color: #9a9890 !important;
        font-size: 0.8rem !important;
        pointer-events: none !important;
    }

    .search-box input {
        padding-left: 34px !important;
        margin-bottom: 0 !important;
    }

    .legend {
        display: flex !important;
        flex-direction: column !important;
        gap: 8px !important;
        margin: 14px 0 16px !important;
        padding-top: 14px !important;
        border-top: 1px solid #f0eee6 !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }

    .legend-item {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        font-size: 0.8rem !important;
        font-weight: 500 !important;
        color: var(--calendar-muted) !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }

    .legend-dot {
        width: 10px !important;
        height: 10px !important;
        border-radius: 50% !important;
        flex-shrink: 0 !important;
    }

    .smart-card {
        background: #FAF8F3 !important;
        border: 1px solid #E8E1D5 !important;
        border-radius: 10px !important;
        padding: 14px !important;
        margin-top: 12px !important;
        width: 100% !important;
        box-sizing: border-box !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }

    .smart-card strong {
        display: block !important;
        font-size: 0.84rem !important;
        color: var(--calendar-text) !important;
        margin-bottom: 4px !important;
    }

    .smart-card span {
        font-size: 0.78rem !important;
        color: var(--calendar-muted) !important;
        line-height: 1.45 !important;
        display: block !important;
    }

    /* Right Main Calendar Panel: Dominant full remaining width */
    .calendar-main {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        width: 100% !important;
        background: #ffffff !important;
        border: 1px solid var(--calendar-border) !important;
        border-radius: 12px !important;
        padding: 20px !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02) !important;
        box-sizing: border-box !important;
        overflow-x: auto !important;
        position: relative !important;
    }

    #calendar {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }

    /* FullCalendar Responsive & Full-Width 7-Column Overrides */
    .fc {
        font-family: inherit !important;
        color: var(--calendar-text) !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    .fc .fc-toolbar.fc-header-toolbar {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 12px !important;
        margin-bottom: 20px !important;
        flex-wrap: wrap !important;
        width: 100% !important;
    }

    .fc .fc-toolbar-chunk:first-child {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
    }

    .fc .fc-toolbar-title {
        font-family: 'Playfair Display', Georgia, serif !important;
        font-size: 1.5rem !important;
        font-weight: 700 !important;
        color: #1e293b !important;
        margin: 0 !important;
        letter-spacing: -0.02em !important;
    }

    .fc .fc-button-group {
        display: inline-flex !important;
        gap: 4px !important;
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
        opacity: 0.5 !important;
        cursor: not-allowed !important;
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

    /* 7-Column Header Grid Alignment (Each Column is 1fr / 14.2857%) */
    .fc-scrollgrid,
    .fc-col-header,
    .fc-daygrid-body,
    .fc-scrollgrid-sync-table,
    .fc-daygrid-body table,
    .fc-col-header table {
        width: 100% !important;
        min-width: 100% !important;
        table-layout: fixed !important;
        box-sizing: border-box !important;
    }

    .fc .fc-col-header-cell {
        width: 14.2857% !important;
        background: #F8F6F1 !important;
        padding: 10px 0 !important;
        font-weight: 700 !important;
        color: #64748b !important;
        text-transform: uppercase !important;
        font-size: 0.75rem !important;
        letter-spacing: 0.05em !important;
        border-color: #e5e0d5 !important;
        text-align: center !important;
        box-sizing: border-box !important;
    }

    .fc .fc-daygrid-day {
        width: 14.2857% !important;
        box-sizing: border-box !important;
    }

    .fc-theme-standard td,
    .fc-theme-standard th,
    .fc-theme-standard .fc-scrollgrid {
        border-color: #e5e0d5 !important;
    }

    .fc .fc-daygrid-day-frame {
        padding: 6px !important;
        min-height: 105px !important;
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
        position: fixed !important;
        right: 28px !important;
        bottom: 28px !important;
        z-index: 200 !important;
        width: 54px !important;
        height: 54px !important;
        border-radius: 50% !important;
        border: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #1e293b !important;
        background: var(--calendar-gold) !important;
        box-shadow: 0 8px 24px rgba(200, 155, 60, 0.4) !important;
        font-size: 1.2rem !important;
        cursor: pointer !important;
        transition: transform 0.15s ease, box-shadow 0.15s ease !important;
    }

    .fab-add:hover {
        transform: scale(1.08) translateY(-2px) !important;
        background: #b58930 !important;
        box-shadow: 0 12px 28px rgba(200, 155, 60, 0.5) !important;
    }

    .calendar-loading {
        position: absolute !important;
        inset: 12px !important;
        display: none !important;
        border-radius: 12px !important;
        background: linear-gradient(90deg, rgba(255,255,255,0.35), rgba(255,255,255,0.85), rgba(255,255,255,0.35)) !important;
        background-size: 220% 100% !important;
        animation: shimmer 1.2s linear infinite !important;
        pointer-events: none !important;
        z-index: 30 !important;
    }

    .calendar-loading.active {
        display: block !important;
    }

    @keyframes shimmer {
        from { background-position: 220% 0; }
        to { background-position: -220% 0; }
    }

    .modal-content {
        border: 1px solid var(--calendar-border) !important;
        border-radius: 14px !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08) !important;
    }

    .modal-header {
        border-bottom: 1px solid var(--calendar-border) !important;
        background: #ffffff !important;
        color: var(--calendar-text) !important;
        border-radius: 14px 14px 0 0 !important;
    }

    .modal-body,
    .modal-footer {
        background: #ffffff !important;
        color: var(--calendar-text) !important;
    }

    .color-row {
        display: flex !important;
        gap: 8px !important;
        flex-wrap: wrap !important;
    }

    .color-swatch {
        width: 32px !important;
        height: 32px !important;
        border-radius: 50% !important;
        border: 3px solid transparent !important;
        cursor: pointer !important;
    }

    .color-swatch.active {
        border-color: #1e293b !important;
    }

    /* Small Screen Responsive Behavior (Horizontal scroll or stack) */
    @media (max-width: 900px) {
        .calendar-layout,
        .calendar-grid {
            grid-template-columns: 1fr !important;
            gap: 18px !important;
        }
        .calendar-sidebar {
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
            position: static !important;
        }
        .calendar-main {
            overflow-x: auto !important;
        }
        .fc-scrollgrid,
        .fc-col-header,
        .fc-daygrid-body,
        .fc-scrollgrid-sync-table,
        .fc-daygrid-body table,
        .fc-col-header table {
            min-width: 650px !important;
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
            <?php echo csrfInput(); ?>
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
const CSRF_TOKEN = '<?php echo e(generateCsrfToken()); ?>';
const apiUrl = '../api/calendar-events.php';
const modal = new bootstrap.Modal(document.getElementById('eventModal'));
const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
const form = document.getElementById('eventForm');
const loading = document.getElementById('calendarLoading');
let calendar;
let searchTimer;
let isSubmitting = false;
let isDeleting = false;

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
    const stack = document.getElementById('toastStack');
    if (!stack) return;
    const el = document.createElement('div');
    el.className = `alert alert-${type === 'error' ? 'danger' : type} shadow-sm alert-dismissible fade show`;
    el.innerHTML = `${message} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    stack.appendChild(el);
    setTimeout(() => {
        if (el.parentNode) el.remove();
    }, 4500);
}

// Convert date to YYYY-MM-DD local representation
function toDateInput(date) {
    if (!date) return '';
    if (typeof date === 'string') {
        const match = date.match(/^\d{4}-\d{2}-\d{2}/);
        if (match) return match[0];
        const parsed = new Date(date);
        if (!isNaN(parsed.getTime())) {
            date = parsed;
        } else {
            return date.slice(0, 10);
        }
    }
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Convert date to HH:MM local representation
function toTimeInput(date) {
    if (!date) return '';
    if (typeof date === 'string') {
        if (date.includes('T')) {
            const timePart = date.split('T')[1];
            return timePart ? timePart.slice(0, 5) : '';
        }
        return date.slice(0, 5);
    }
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
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
        csrf_token: CSRF_TOKEN
    };
}

async function saveEvent(payload) {
    const isEdit = Boolean(payload.schedule_id);
    payload.csrf_token = CSRF_TOKEN;
    const response = await fetch(apiUrl, {
        method: isEdit ? 'PUT' : 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN,
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
    });
    let data;
    try {
        data = await response.json();
    } catch (e) {
        throw new Error('Server returned an unexpected response. Please try again.');
    }
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
        end_time: toTimeInput(info.event.end),
        csrf_token: CSRF_TOKEN
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
    if (isSubmitting) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHtml = submitBtn ? submitBtn.innerHTML : '<i class="fas fa-check"></i> Save';

    const payload = formPayload();

    if (!payload.title) {
        toast('Please enter a schedule title.', 'error');
        document.getElementById('title').focus();
        return;
    }
    if (!payload.event_date) {
        toast('Please select a schedule date.', 'error');
        document.getElementById('event_date').focus();
        return;
    }
    if (!payload.start_time) {
        toast('Please enter a start time.', 'error');
        document.getElementById('start_time').focus();
        return;
    }
    if (payload.end_time && payload.end_time <= payload.start_time) {
        toast('End time must be later than start time.', 'error');
        document.getElementById('end_time').focus();
        return;
    }

    isSubmitting = true;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving...';
    }

    try {
        const data = await saveEvent(payload);
        modal.hide();
        calendar.refetchEvents();
        toast(data.message || 'Schedule saved successfully.');
    } catch (error) {
        toast(error.message || 'Unable to save schedule.', 'error');
    } finally {
        isSubmitting = false;
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    }
});

document.getElementById('deleteEventBtn').addEventListener('click', async function() {
    const id = document.getElementById('schedule_id').value;
    if (!id || isDeleting || !confirm('Are you sure you want to delete this schedule?')) {
        return;
    }

    const deleteBtn = document.getElementById('deleteEventBtn');
    const originalDeleteHtml = deleteBtn ? deleteBtn.innerHTML : '<i class="fas fa-trash"></i> Delete';
    isDeleting = true;
    if (deleteBtn) {
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Deleting...';
    }

    try {
        const response = await fetch(apiUrl, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({schedule_id: id, csrf_token: CSRF_TOKEN})
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to delete schedule.');
        }
        modal.hide();
        calendar.refetchEvents();
        toast(data.message || 'Schedule deleted successfully.');
    } catch (error) {
        toast(error.message || 'Unable to delete schedule.', 'error');
    } finally {
        isDeleting = false;
        if (deleteBtn) {
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = originalDeleteHtml;
        }
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
