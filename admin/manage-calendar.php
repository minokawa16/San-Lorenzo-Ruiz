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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> | Parish Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --calendar-primary: #1a73e8;
            --calendar-ink: #172033;
            --calendar-muted: #64748b;
            --calendar-line: #e5e7eb;
            --calendar-bg: #f6f8fc;
            --calendar-card: #ffffff;
            --calendar-soft: 0 18px 45px rgba(15, 23, 42, 0.12);
        }

        body {
            margin: 0;
            background: var(--calendar-bg);
            color: var(--calendar-ink);
            font-family: Inter, Manrope, Arial, sans-serif;
        }

        body.calendar-dark {
            --calendar-ink: #e5eefb;
            --calendar-muted: #9fb0c6;
            --calendar-line: #233148;
            --calendar-bg: #101827;
            --calendar-card: #172033;
        }

        .admin-layout {
            min-height: 100vh;
            display: flex;
        }

        .calendar-shell {
            width: 100%;
            margin-left: 260px;
            padding: 18px;
            transition: margin-left 0.25s ease;
        }

        body.admin-sidebar-collapsed .calendar-shell {
            margin-left: 88px;
        }

        .calendar-topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 14px;
            margin-bottom: 14px;
            background: rgba(246, 248, 252, 0.88);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(226, 232, 240, 0.72);
            border-radius: 22px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
        }

        body.calendar-dark .calendar-topbar {
            background: rgba(16, 24, 39, 0.88);
            border-color: rgba(51, 65, 85, 0.75);
        }

        .calendar-title {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 210px;
        }

        .calendar-title-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: white;
            background: linear-gradient(135deg, #1a73e8, #34a853);
            box-shadow: 0 10px 24px rgba(26, 115, 232, 0.25);
        }

        .calendar-title h1 {
            margin: 0;
            font-size: 1.12rem;
            font-weight: 800;
            letter-spacing: 0;
        }

        .calendar-title span {
            color: var(--calendar-muted);
            font-size: 0.82rem;
        }

        .calendar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .toolbar-btn,
        .icon-btn {
            border: 1px solid var(--calendar-line);
            background: var(--calendar-card);
            color: var(--calendar-ink);
            min-height: 40px;
            border-radius: 999px;
            padding: 8px 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .icon-btn {
            width: 40px;
            padding: 0;
            justify-content: center;
        }

        .toolbar-btn:hover,
        .icon-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            gap: 16px;
        }

        .calendar-sidebar,
        .calendar-main {
            background: var(--calendar-card);
            border: 1px solid var(--calendar-line);
            border-radius: 24px;
            box-shadow: var(--calendar-soft);
        }

        .calendar-sidebar {
            padding: 18px;
            height: calc(100vh - 112px);
            position: sticky;
            top: 96px;
            overflow: auto;
        }

        .calendar-main {
            min-height: calc(100vh - 112px);
            padding: 12px;
            position: relative;
        }

        .mini-month {
            width: 100%;
            border: 1px solid var(--calendar-line);
            color: var(--calendar-ink);
            background: transparent;
            border-radius: 16px;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        .search-box {
            position: relative;
            margin-bottom: 12px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--calendar-muted);
        }

        .search-box input,
        .filter-select {
            width: 100%;
            border: 1px solid var(--calendar-line);
            background: transparent;
            color: var(--calendar-ink);
            border-radius: 16px;
            min-height: 44px;
            padding: 10px 12px;
        }

        .search-box input {
            padding-left: 40px;
        }

        .filter-select {
            margin-bottom: 10px;
        }

        .legend {
            display: grid;
            gap: 9px;
            margin: 16px 0;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--calendar-muted);
            font-size: 0.9rem;
        }

        .legend-dot {
            width: 11px;
            height: 11px;
            border-radius: 999px;
        }

        .smart-card {
            padding: 14px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(26, 115, 232, 0.1), rgba(52, 168, 83, 0.1));
            border: 1px solid rgba(26, 115, 232, 0.16);
        }

        .smart-card strong {
            display: block;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .smart-card span {
            color: var(--calendar-muted);
            font-size: 0.82rem;
        }

        #calendar {
            min-height: 720px;
        }

        .fc {
            color: var(--calendar-ink);
        }

        .fc .fc-toolbar-title {
            font-size: 1.22rem;
            font-weight: 800;
            letter-spacing: 0;
        }

        .fc .fc-button-primary {
            background: var(--calendar-card);
            border: 1px solid var(--calendar-line);
            color: var(--calendar-ink);
            border-radius: 999px;
            font-weight: 700;
            box-shadow: none;
        }

        .fc .fc-button-primary:hover,
        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background: var(--calendar-primary);
            border-color: var(--calendar-primary);
            color: #fff;
        }

        .fc-theme-standard td,
        .fc-theme-standard th,
        .fc-theme-standard .fc-scrollgrid {
            border-color: var(--calendar-line);
        }

        .fc .fc-daygrid-day-frame {
            padding: 6px;
        }

        .fc-event {
            border: 0;
            border-radius: 999px;
            padding: 2px 7px;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.14);
            cursor: pointer;
        }

        .fab-add {
            position: fixed;
            right: 28px;
            bottom: 28px;
            z-index: 200;
            width: 62px;
            height: 62px;
            border-radius: 22px;
            border: 0;
            display: grid;
            place-items: center;
            color: #fff;
            background: linear-gradient(135deg, #1a73e8, #0f9d58);
            box-shadow: 0 18px 38px rgba(26, 115, 232, 0.35);
            font-size: 1.25rem;
        }

        .calendar-loading {
            position: absolute;
            inset: 12px;
            display: none;
            border-radius: 20px;
            background: linear-gradient(90deg, rgba(255,255,255,0.35), rgba(255,255,255,0.85), rgba(255,255,255,0.35));
            background-size: 220% 100%;
            animation: shimmer 1.2s linear infinite;
            pointer-events: none;
            z-index: 30;
        }

        body.calendar-dark .calendar-loading {
            background: linear-gradient(90deg, rgba(15,23,42,0.25), rgba(51,65,85,0.65), rgba(15,23,42,0.25));
            background-size: 220% 100%;
        }

        .calendar-loading.active {
            display: block;
        }

        @keyframes shimmer {
            from { background-position: 220% 0; }
            to { background-position: -220% 0; }
        }

        .modal-content {
            border: 0;
            border-radius: 24px;
            box-shadow: var(--calendar-soft);
        }

        .modal-header {
            border-bottom: 1px solid var(--calendar-line);
            background: var(--calendar-card);
            color: var(--calendar-ink);
            border-radius: 24px 24px 0 0;
        }

        .modal-body,
        .modal-footer {
            background: var(--calendar-card);
            color: var(--calendar-ink);
        }

        .color-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .color-swatch {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 3px solid transparent;
            cursor: pointer;
        }

        .color-swatch.active {
            border-color: var(--calendar-ink);
        }

        .toast-stack {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
        }

        @media (max-width: 1080px) {
            .calendar-grid {
                grid-template-columns: 1fr;
            }

            .calendar-sidebar {
                position: static;
                height: auto;
            }
        }

        @media (max-width: 768px) {
            .calendar-shell {
                margin-left: 0;
                padding: 10px;
            }

            body.admin-sidebar-collapsed .calendar-shell {
                margin-left: 0;
            }

            .calendar-topbar {
                align-items: flex-start;
                flex-direction: column;
                border-radius: 18px;
            }

            .calendar-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .toolbar-btn span {
                display: none;
            }

            .calendar-main {
                padding: 8px;
                border-radius: 18px;
            }

            #calendar {
                min-height: 640px;
            }

            .fab-add {
                width: 56px;
                height: 56px;
                right: 18px;
                bottom: 18px;
            }
        }

        @media print {
            .admin-sidebar,
            .calendar-topbar,
            .calendar-sidebar,
            .fab-add,
            .fc-header-toolbar {
                display: none !important;
            }

            .calendar-shell {
                margin: 0;
                padding: 0;
            }

            .calendar-main {
                box-shadow: none;
                border: 0;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body>
<div class="parish-toast-container" id="parishToastContainer" aria-live="polite" aria-atomic="true"></div>
<div class="admin-layout">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="calendar-shell">
        <div class="calendar-topbar">
            <div class="calendar-title">
                <div class="calendar-title-icon"><i class="fas fa-calendar-days"></i></div>
                <div>
                    <h1>Calendar & Scheduling</h1>
                    <span>Manage parish events, tasks, reservations, meetings, and sacramental schedules.</span>
                </div>
            </div>
        </div>

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
    </main>
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
        initialView: window.innerWidth < 700 ? 'listWeek' : 'dayGridMonth',
        height: 'auto',
        nowIndicator: true,
        selectable: true,
        editable: true,
        eventResizableFromStart: true,
        dayMaxEvents: 3,
        headerToolbar: {
            left: 'prev,next',
            center: 'title',
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
</body>
</html>
