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

    /* ── Horizontal Top Filter Toolbar ── */
    .calendar-filter-toolbar {
        background: #ffffff !important;
        border: 1px solid var(--calendar-border) !important;
        border-radius: 12px !important;
        padding: 16px 18px !important;
        margin-bottom: 20px !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02) !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }

    .calendar-filters-row {
        display: flex !important;
        align-items: center !important;
        gap: 14px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }

    /* Desktop: Month ~200px, Search flexible, Category & Status ~200px each */
    .filter-col-month {
        flex: 0 0 200px !important;
        width: 200px !important;
    }

    .filter-col-search {
        flex: 1 1 auto !important;
        min-width: 200px !important;
    }

    .filter-col-category {
        flex: 0 0 200px !important;
        width: 200px !important;
    }

    .filter-col-status {
        flex: 0 0 200px !important;
        width: 200px !important;
    }

    .filter-control-wrap {
        position: relative !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }

    /* Uniform controls: white, thin border, 10px radius, 44px height, gold focus */
    .calendar-top-control {
        width: 100% !important;
        height: 44px !important;
        line-height: 44px !important;
        border: 1px solid var(--calendar-border) !important;
        border-radius: 10px !important;
        padding: 0 14px !important;
        font-size: 0.88rem !important;
        font-family: inherit !important;
        font-weight: 500 !important;
        color: var(--calendar-text) !important;
        background: #ffffff !important;
        outline: none !important;
        box-sizing: border-box !important;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease !important;
    }

    .calendar-top-control:focus {
        border-color: var(--calendar-gold) !important;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.18) !important;
        background: #ffffff !important;
    }

    /* Search magnifier icon inside input on left without covering text */
    .search-box-wrap {
        position: relative !important;
        width: 100% !important;
    }

    .search-box-wrap .search-icon {
        position: absolute !important;
        left: 14px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        color: #94a3b8 !important;
        font-size: 0.88rem !important;
        pointer-events: none !important;
        z-index: 2 !important;
    }

    .search-box-wrap input.calendar-top-control {
        padding-left: 42px !important;
    }

    /* Month picker: single calendar icon */
    .filter-col-month input[type="month"] {
        cursor: pointer !important;
    }

    /* Category & Status custom chevron & no clipped text */
    .select-wrap select.calendar-top-control {
        appearance: none !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2364748B' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
        background-repeat: no-repeat !important;
        background-position: right 14px center !important;
        background-size: 12px 10px !important;
        padding-right: 40px !important;
        padding-left: 14px !important;
        cursor: pointer !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    /* Clear filters link */
    .calendar-filter-actions {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        margin-top: 10px !important;
        padding-top: 10px !important;
        border-top: 1px dashed #e8e5dc !important;
    }

    .btn-clear-filters {
        background: none !important;
        border: none !important;
        color: #b45309 !important;
        font-size: 0.82rem !important;
        font-weight: 600 !important;
        padding: 4px 10px !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        border-radius: 6px !important;
        transition: all 0.15s ease !important;
    }

    .btn-clear-filters:hover {
        color: #92400e !important;
        background: #fef3c7 !important;
        text-decoration: underline !important;
    }

    /* ── Main Full-Width Calendar Card ── */
    .calendar-main {
        width: 100% !important;
        background: #ffffff !important;
        border: 1px solid var(--calendar-border) !important;
        border-radius: 14px !important;
        padding: 22px !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02) !important;
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

    /* 7-Column Header Grid Alignment */
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
        padding: 4px 6px !important;
        min-height: 110px !important;
        transition: background-color 0.12s ease !important;
        box-sizing: border-box !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
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

    /* ── Event Pill Styling (Month View) ── */
    .fc .fc-daygrid-event-harness {
        margin-bottom: 2px !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    .fc .fc-daygrid-event {
        margin: 0 !important;
        padding: 0 !important;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    .calendar-pill-item {
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
        background: #ffffff !important;
        border: 1px solid #e2ded5 !important;
        border-radius: 6px !important;
        padding: 3px 6px !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        cursor: pointer !important;
        transition: all 0.12s ease !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
    }

    .calendar-pill-item:hover,
    .calendar-pill-item:focus {
        background: #fdfbf7 !important;
        border-color: var(--calendar-gold) !important;
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.18) !important;
        outline: none !important;
    }

    .calendar-pill-item .pill-dot {
        width: 7px !important;
        height: 7px !important;
        border-radius: 50% !important;
        flex-shrink: 0 !important;
        display: inline-block !important;
    }

    .calendar-pill-item .pill-time {
        font-size: 12px !important;
        font-weight: 500 !important;
        color: #64748b !important;
        flex-shrink: 0 !important;
        line-height: 1 !important;
    }

    .calendar-pill-item .pill-title {
        font-size: 12px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        flex: 1 1 auto !important;
        min-width: 0 !important;
        line-height: 1.25 !important;
    }

    /* More Events Link */
    .fc .fc-daygrid-more-link {
        font-size: 0.75rem !important;
        font-weight: 700 !important;
        color: var(--calendar-gold-dark, #8a6409) !important;
        background: #faf4e6 !important;
        padding: 2px 8px !important;
        border-radius: 4px !important;
        margin-top: 2px !important;
        display: inline-block !important;
        text-decoration: none !important;
        transition: all 0.15s ease !important;
    }

    .fc .fc-daygrid-more-link:hover {
        background: #f4e8cc !important;
        color: #6d4e04 !important;
    }

    /* Week & Day View Custom Styling */
    .calendar-timegrid-item {
        padding: 2px 4px !important;
        border-radius: 4px !important;
        background: #ffffff !important;
        font-size: 12px !important;
        line-height: 1.2 !important;
        overflow: hidden !important;
    }

    .calendar-timegrid-item .pill-time {
        font-size: 11px !important;
        font-weight: 500 !important;
        color: #64748b !important;
    }

    .calendar-timegrid-item .pill-title {
        font-size: 12px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    /* Agenda (List) View Custom Styling */
    .fc .fc-list-event-time,
    .fc .fc-list-event-graphic {
        display: none !important;
    }

    .calendar-list-item {
        padding: 4px 0 !important;
        font-size: 13px !important;
    }

    .calendar-list-item .pill-dot {
        width: 8px !important;
        height: 8px !important;
        border-radius: 50% !important;
        flex-shrink: 0 !important;
    }

    .calendar-list-item .pill-time {
        font-size: 12px !important;
        font-weight: 600 !important;
        color: #64748b !important;
        white-space: nowrap !important;
    }

    .calendar-list-item .pill-title {
        font-size: 13px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
    }

    /* Tooltip Customization */
    .calendar-event-tooltip .tooltip-inner {
        background-color: #1e293b !important;
        color: #f8fafc !important;
        padding: 10px 14px !important;
        border-radius: 8px !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2) !important;
        max-width: 280px !important;
        font-size: 0.82rem !important;
        line-height: 1.4 !important;
    }

    .calendar-tooltip .tooltip-title {
        font-weight: 700;
        font-size: 0.88rem;
        color: #ffffff;
        margin-bottom: 6px;
        word-break: break-word;
    }

    .calendar-tooltip .tooltip-line {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.78rem;
        color: #cbd5e1;
        margin-bottom: 3px;
    }

    .calendar-tooltip .tooltip-line:last-child {
        margin-bottom: 0;
    }

    /* Time Gutter Styling */
    .fc .fc-timegrid-slot-label-cushion {
        font-size: 0.78rem !important;
        font-weight: 600 !important;
        color: #64748b !important;
        text-transform: lowercase !important;
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

    /* Small Screen Responsive Behavior: 2 columns on tablet, 1 column on mobile */
    @media (max-width: 991px) {
        .calendar-filters-row {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px !important;
        }
        .filter-col-month,
        .filter-col-search,
        .filter-col-category,
        .filter-col-status {
            flex: none !important;
            width: 100% !important;
            min-width: 0 !important;
        }
    }

    @media (max-width: 576px) {
        .calendar-filters-row {
            grid-template-columns: 1fr !important;
            gap: 10px !important;
        }
        .calendar-main {
            padding: 14px !important;
            overflow-x: auto !important;
        }
        .fc-scrollgrid,
        .fc-col-header,
        .fc-daygrid-body,
        .fc-scrollgrid-sync-table,
        .fc-daygrid-body table,
        .fc-col-header table {
            min-width: 600px !important;
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

        <!-- Top Horizontal Filter Toolbar -->
        <div class="calendar-filter-toolbar">
            <div class="calendar-filters-row">
                <div class="filter-col filter-col-month">
                    <div class="filter-control-wrap">
                        <input class="calendar-top-control mini-month" type="month" id="miniMonth" value="<?php echo date('Y-m'); ?>" title="Filter by month" aria-label="Filter by month">
                    </div>
                </div>

                <div class="filter-col filter-col-search">
                    <div class="filter-control-wrap search-box-wrap">
                        <i class="fas fa-search search-icon"></i>
                        <input class="calendar-top-control" type="search" id="calendarSearch" placeholder="Search schedules" aria-label="Search schedules">
                    </div>
                </div>

                <div class="filter-col filter-col-category">
                    <div class="filter-control-wrap select-wrap">
                        <select class="calendar-top-control filter-select" id="categoryFilter" aria-label="Filter by category">
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
                    </div>
                </div>

                <div class="filter-col filter-col-status">
                    <div class="filter-control-wrap select-wrap">
                        <select class="calendar-top-control filter-select" id="statusFilter" aria-label="Filter by status">
                            <option value="all">All statuses</option>
                            <option value="upcoming">Upcoming</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="finished">Finished</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="calendar-filter-actions" id="clearFiltersWrap" style="display: none;">
                <button type="button" class="btn-clear-filters" id="clearFiltersBtn">
                    <i class="fas fa-rotate-left me-1"></i> Clear filters
                </button>
            </div>
        </div>

        <!-- Full-Width Calendar Card -->
        <section class="calendar-main">
            <div class="calendar-loading" id="calendarLoading"></div>
            <div id="calendar"></div>
        </section>
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
<script src="../assets/js/main.js?v=20260930_cal"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
const CSRF_TOKEN = '<?php echo e(generateCsrfToken()); ?>';
const apiUrl = '../api/calendar-events.php';
const modal = new bootstrap.Modal(document.getElementById('eventModal'));
const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
const form = document.getElementById('eventForm');
const loading = document.getElementById('calendarLoading');
const defaultMonthValue = '<?php echo date('Y-m'); ?>';
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

// HTML escape helper
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Shared Time Formatter - 12-hour style with lowercase am/pm, no leading zeros (8am, 9am, 1pm, 8:30am, 1:15pm). Never "8a" or "9p".
function formatManilaTime(dateInput) {
    if (!dateInput) return '';

    // Handle object with startStr/dateStr
    if (typeof dateInput === 'object' && dateInput !== null) {
        if (dateInput.startStr) dateInput = dateInput.startStr;
        else if (dateInput.dateStr) dateInput = dateInput.dateStr;
    }

    if (typeof dateInput === 'string') {
        const timeMatch = dateInput.match(/(?:T|\s|^)(\d{1,2}):(\d{2})(?::(\d{2}))?/);
        if (timeMatch) {
            let hours = parseInt(timeMatch[1], 10);
            const minutes = parseInt(timeMatch[2], 10);
            const ampm = hours >= 12 ? 'pm' : 'am';
            hours = hours % 12;
            if (hours === 0) hours = 12;
            return minutes === 0 ? `${hours}${ampm}` : `${hours}:${String(minutes).padStart(2, '0')}${ampm}`;
        }
    }

    const d = (dateInput instanceof Date) ? dateInput : new Date(dateInput);
    if (isNaN(d.getTime())) return '';

    try {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Manila',
            hour: 'numeric',
            minute: 'numeric',
            hour12: true
        });
        const parts = formatter.formatToParts(d);
        let h = 0, m = 0, ampm = 'am';
        for (const p of parts) {
            if (p.type === 'hour') h = parseInt(p.value, 10);
            if (p.type === 'minute') m = parseInt(p.value, 10);
            if (p.type === 'dayPeriod') ampm = p.value.toLowerCase();
        }
        return m === 0 ? `${h}${ampm}` : `${h}:${String(m).padStart(2, '0')}${ampm}`;
    } catch (e) {
        let h = d.getHours();
        const m = d.getMinutes();
        const ampm = h >= 12 ? 'pm' : 'am';
        h = h % 12;
        if (h === 0) h = 12;
        return m === 0 ? `${h}${ampm}` : `${h}:${String(m).padStart(2, '0')}${ampm}`;
    }
}

// Shared Time Range Formatter - e.g. "9am – 10am" or "All day"
function formatManilaTimeRange(start, end, allDay = false) {
    if (allDay) return 'All day';
    const s = formatManilaTime(start);
    const e = formatManilaTime(end);
    if (s && e) {
        return `${s} – ${e}`;
    }
    return s || e || 'All day';
}

// Shared Date Formatter in Asia/Manila - e.g. "Wed, Oct 5, 2026"
function formatManilaDate(dateInput) {
    if (!dateInput) return '';
    const d = (dateInput instanceof Date) ? dateInput : new Date(dateInput);
    if (isNaN(d.getTime())) return '';
    try {
        return d.toLocaleDateString('en-US', {
            timeZone: 'Asia/Manila',
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    } catch (e) {
        return d.toDateString();
    }
}

function categoryLabel(cat) {
    const map = {
        event: 'Parish Event',
        mass: 'Mass / Public Schedule',
        monthly_mass: 'Monthly Mass',
        sacramental: 'Sacramental Services',
        patronal_fiesta: 'Patronal Fiesta',
        meeting: 'Meeting',
        task: 'Task',
        blessing: 'Blessing',
        reservation: 'Reservation',
        announcement: 'Announcement'
    };
    return map[cat] || (cat ? String(cat).charAt(0).toUpperCase() + String(cat).slice(1).replace(/_/g, ' ') : 'Event');
}

function statusLabel(status) {
    const map = {
        upcoming: 'Upcoming',
        ongoing: 'Ongoing',
        finished: 'Finished',
        cancelled: 'Cancelled'
    };
    return map[status] || (status ? String(status).charAt(0).toUpperCase() + String(status).slice(1) : 'Upcoming');
}

// Toast Function
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
        const timeMatch = date.match(/(?:T|\s|^)(\d{1,2}):(\d{2})/);
        if (timeMatch) {
            return `${timeMatch[1].padStart(2, '0')}:${timeMatch[2]}`;
        }
        return date.slice(0, 5);
    }
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
}

// Event Filters Function
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

// Check if any filter is active
function isFilterActive() {
    const q = document.getElementById('calendarSearch')?.value.trim() || '';
    const cat = document.getElementById('categoryFilter')?.value || 'all';
    const stat = document.getElementById('statusFilter')?.value || 'all';
    const m = document.getElementById('miniMonth')?.value || defaultMonthValue;
    return q !== '' || cat !== 'all' || stat !== 'all' || m !== defaultMonthValue;
}

// Toggle Clear filters link visibility
function updateClearFiltersVisibility() {
    const wrap = document.getElementById('clearFiltersWrap');
    if (wrap) {
        wrap.style.display = isFilterActive() ? 'flex' : 'none';
    }
}

// Reset all filters to default state
function clearAllFilters() {
    const searchEl = document.getElementById('calendarSearch');
    const catEl = document.getElementById('categoryFilter');
    const statEl = document.getElementById('statusFilter');
    const monthEl = document.getElementById('miniMonth');
    if (searchEl) searchEl.value = '';
    if (catEl) catEl.value = 'all';
    if (statEl) statEl.value = 'all';
    if (monthEl) monthEl.value = defaultMonthValue;
    updateClearFiltersVisibility();
    if (calendar) {
        calendar.gotoDate(defaultMonthValue + '-01');
        calendar.refetchEvents();
    }
}

// Reset Form Function
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

// Set Active Color Function
function setActiveColor(color) {
    document.getElementById('color_label').value = color;
    document.querySelectorAll('.color-swatch').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.color === color);
    });
}

// Fill Form Function
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

// Form Payload Function
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

// Show Details Function in Event Details Modal
function showDetails(event) {
    const props = event.extendedProps || {};
    const timeRange = formatManilaTimeRange(event.startStr || event.start, event.endStr || event.end, event.allDay);
    const dateStr = formatManilaDate(event.start);
    document.getElementById('detailsTitle').textContent = event.title;
    document.getElementById('detailsBody').innerHTML = `
        <div class="d-grid gap-2">
            <div><strong>When:</strong> ${escapeHtml(dateStr)} at ${escapeHtml(timeRange)}</div>
            <div><strong>Category:</strong> <span class="badge bg-secondary">${escapeHtml(categoryLabel(props.category || 'schedule'))}</span></div>
            <div><strong>Status:</strong> <span class="badge bg-success">${escapeHtml(statusLabel(props.status || 'upcoming'))}</span></div>
            <div><strong>Location:</strong> ${escapeHtml(props.location || 'San Lorenzo Ruiz Parish')}</div>
            <div><strong>Source:</strong> ${escapeHtml(props.source_type || 'schedule')}</div>
            ${props.description ? `<div class="mt-2 p-2 bg-light rounded"><strong>Description:</strong><p class="mb-0 mt-1">${escapeHtml(props.description)}</p></div>` : ''}
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
        moreLinkClick: function(arg) {
            calendar.changeView('timeGridDay', arg.date);
        },
        moreLinkText: function(num) {
            return `+${num} more`;
        },
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
        // Format time gutter as "8am, 9am, 10am..."
        slotLabelContent: function(arg) {
            return formatManilaTime(arg.date);
        },
        // Sort events in each cell strictly by start time
        eventOrder: function(a, b) {
            if (a.allDay && !b.allDay) return -1;
            if (!a.allDay && b.allDay) return 1;
            const aStart = a.start ? a.start.getTime() : 0;
            const bStart = b.start ? b.start.getTime() : 0;
            if (aStart !== bStart) return aStart - bStart;
            return (a.title || '').localeCompare(b.title || '');
        },
        // Sync month picker and update clear filters link
        datesSet: function(dateInfo) {
            const current = dateInfo.view.currentStart;
            const year = current.getFullYear();
            const month = String(current.getMonth() + 1).padStart(2, '0');
            const miniMonth = document.getElementById('miniMonth');
            if (miniMonth && miniMonth.value !== `${year}-${month}`) {
                miniMonth.value = `${year}-${month}`;
            }
            updateClearFiltersVisibility();
        },
        // Custom event pill rendering for Month, Week, Day, and Agenda views
        eventContent: function(arg) {
            const isAllDay = arg.event.allDay;
            const timeText = isAllDay ? 'All day' : formatManilaTime(arg.event.startStr || arg.event.start);
            const timeRange = formatManilaTimeRange(arg.event.startStr || arg.event.start, arg.event.endStr || arg.event.end, isAllDay);
            const color = arg.event.backgroundColor || arg.event.borderColor || defaultColors[arg.event.extendedProps?.category] || '#1a73e8';
            const title = arg.event.title || 'Untitled';

            if (arg.view.type === 'dayGridMonth') {
                return {
                    html: `
                        <div class="calendar-pill-item" data-id="${escapeHtml(arg.event.id)}">
                            <span class="pill-dot" style="background-color: ${escapeHtml(color)}"></span>
                            <span class="pill-time">${escapeHtml(timeText)}</span>
                            <span class="pill-title">${escapeHtml(title)}</span>
                        </div>
                    `
                };
            } else if (arg.view.type === 'timeGridWeek' || arg.view.type === 'timeGridDay') {
                return {
                    html: `
                        <div class="calendar-timegrid-item" style="border-left: 3px solid ${escapeHtml(color)}">
                            <div class="pill-time">${escapeHtml(timeRange)}</div>
                            <div class="pill-title">${escapeHtml(title)}</div>
                        </div>
                    `
                };
            } else if (arg.view.type === 'listWeek') {
                return {
                    html: `
                        <div class="calendar-list-item d-flex align-items-center gap-2">
                            <span class="pill-dot" style="background-color: ${escapeHtml(color)}; width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0;"></span>
                            <span class="pill-time text-muted fw-semibold" style="min-width: 95px; font-size: 0.85rem; white-space: nowrap;">${escapeHtml(timeRange)}</span>
                            <span class="pill-title fw-bold text-dark" style="font-size: 0.88rem;">${escapeHtml(title)}</span>
                        </div>
                    `
                };
            }
        },
        // Tooltip initialization on hover & focus
        eventDidMount: function(info) {
            const event = info.event;
            const props = event.extendedProps || {};
            const timeRange = formatManilaTimeRange(event.startStr || event.start, event.endStr || event.end, event.allDay);
            const cat = categoryLabel(props.category || 'event');
            const stat = statusLabel(props.status || 'upcoming');

            info.el.setAttribute('tabindex', '0');
            info.el.setAttribute('role', 'button');
            info.el.setAttribute('aria-label', `${event.title}, ${timeRange}, ${cat}, ${stat}`);

            const tooltip = new bootstrap.Tooltip(info.el, {
                title: `
                    <div class="calendar-tooltip text-start">
                        <div class="tooltip-title">${escapeHtml(event.title || 'Untitled')}</div>
                        <div class="tooltip-line"><i class="far fa-clock me-1 text-warning"></i> <span>${escapeHtml(timeRange)}</span></div>
                        <div class="tooltip-line"><i class="fas fa-tag me-1 text-info"></i> <span>${escapeHtml(cat)}</span></div>
                        <div class="tooltip-line"><i class="fas fa-circle-check me-1 text-success"></i> <span>${escapeHtml(stat)}</span></div>
                    </div>
                `,
                html: true,
                placement: 'top',
                trigger: 'hover focus',
                container: 'body',
                customClass: 'calendar-event-tooltip'
            });
            info.el._bsTooltip = tooltip;
        },
        eventWillUnmount: function(info) {
            if (info.el._bsTooltip) {
                info.el._bsTooltip.dispose();
            }
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
            if (info.el && info.el._bsTooltip) {
                info.el._bsTooltip.hide();
            }
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
    updateClearFiltersVisibility();
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
    if (this.value && calendar) {
        calendar.gotoDate(this.value + '-01');
    }
    updateClearFiltersVisibility();
});

['categoryFilter', 'statusFilter'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => {
        calendar.refetchEvents();
        updateClearFiltersVisibility();
    });
});

document.getElementById('calendarSearch').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        calendar.refetchEvents();
        updateClearFiltersVisibility();
    }, 280);
});

document.getElementById('clearFiltersBtn')?.addEventListener('click', clearAllFilters);

document.getElementById('category').addEventListener('change', function() {
    setActiveColor(defaultColors[this.value] || '#1a73e8');
});

document.querySelectorAll('.color-swatch').forEach(btn => {
    btn.addEventListener('click', () => setActiveColor(btn.dataset.color));
});
</script>
</div>
<?php include '../templates/footer.php'; ?>
