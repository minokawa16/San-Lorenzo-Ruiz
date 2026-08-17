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
        margin-bottom: 18px;
    }

    .calendar-user-title {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .calendar-user-icon {
        width: 52px;
        height: 52px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        color: #fff;
        background: linear-gradient(135deg, #1a73e8, #34a853);
        box-shadow: 0 14px 30px rgba(26, 115, 232, 0.24);
    }

    .calendar-user-title h1 {
        margin: 0;
        font-weight: 900;
        font-size: clamp(1.4rem, 2.2vw, 2rem);
        letter-spacing: 0;
    }

    .calendar-user-title p {
        margin: 3px 0 0;
        color: var(--text-muted, #64748b);
    }

    .calendar-user-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .calendar-user-grid {
        display: grid;
        grid-template-columns: 300px minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }

    .calendar-panel,
    .calendar-side-panel {
        background: var(--surface-color, #fff);
        border: 1px solid #eef2f7;
        border-radius: 8px;
        box-shadow: var(--shadow-soft, 0 8px 24px rgba(16,24,40,0.08));
    }

    .calendar-panel {
        padding: 12px;
        min-height: 740px;
        position: relative;
    }

    .calendar-side-panel {
        padding: 18px;
        position: sticky;
        top: 96px;
        max-height: calc(100vh - 118px);
        overflow: auto;
    }

    .calendar-side-section {
        display: grid;
        gap: 10px;
        margin-bottom: 18px;
    }

    .calendar-side-section h2,
    .upcoming-header h2 {
        margin: 0;
        font-size: 1rem;
        font-weight: 900;
    }

    .calendar-side-section .form-control,
    .calendar-side-section .form-select,
    .mini-month {
        width: 100%;
        border-radius: 8px;
        min-height: 42px;
    }

    .mini-month {
        border: 1px solid #e5e7eb;
        padding: 8px 10px;
        color: #172033;
        background: #fff;
    }

    .calendar-legend {
        display: grid;
        gap: 8px;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text-muted, #64748b);
        font-size: 0.88rem;
    }

    .legend-dot {
        width: 11px;
        height: 11px;
        border-radius: 999px;
        flex: 0 0 11px;
    }

    .upcoming-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .event-stack {
        display: grid;
        gap: 10px;
    }

    .event-card {
        border: 1px solid #eef2f7;
        border-left: 5px solid var(--event-color, #1a73e8);
        border-radius: 8px;
        padding: 12px;
        background: rgba(248, 250, 252, 0.82);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .event-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.1);
    }

    .event-card strong {
        display: block;
        font-size: 0.95rem;
        margin-bottom: 4px;
    }

    .event-card span,
    .event-card small {
        color: var(--text-muted, #64748b);
    }

    .calendar-loading {
        position: absolute;
        inset: 12px;
        display: none;
        border-radius: 8px;
        background: linear-gradient(90deg, rgba(255,255,255,0.4), rgba(255,255,255,0.92), rgba(255,255,255,0.4));
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

    .fc {
        color: #172033;
    }

    .fc .fc-toolbar-title {
        font-size: 1.18rem;
        font-weight: 900;
        letter-spacing: 0;
    }

    .fc .fc-button-primary {
        background: #fff;
        border: 1px solid #e5e7eb;
        color: #172033;
        border-radius: 999px;
        font-weight: 800;
        box-shadow: none;
    }

    .fc .fc-button-primary:hover,
    .fc .fc-button-primary:not(:disabled).fc-button-active {
        background: #1a73e8;
        border-color: #1a73e8;
        color: #fff;
    }

    .fc-theme-standard td,
    .fc-theme-standard th,
    .fc-theme-standard .fc-scrollgrid {
        border-color: #e5e7eb;
    }

    .fc .fc-daygrid-day-frame {
        min-height: 118px;
        padding: 6px;
    }

    .fc .fc-day-today {
        background: rgba(247, 223, 158, 0.26) !important;
    }

    .fc-event {
        border: 0;
        border-radius: 8px;
        padding: 3px 7px;
        background: rgba(255, 255, 255, 0.96) !important;
        border: 1px solid rgba(23, 32, 51, 0.12) !important;
        color: #172033 !important;
        box-shadow: 0 5px 14px rgba(15, 23, 42, 0.12);
        cursor: pointer;
    }

    .fc-h-event .fc-event-main {
        color: #172033 !important;
    }

    .user-calendar-event {
        display: flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
        color: #172033;
        font-size: 0.78rem;
        font-weight: 800;
    }

    .user-calendar-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        flex: 0 0 8px;
        background: var(--event-dot, #1a73e8);
        box-shadow: 0 0 0 2px rgba(255,255,255,0.9);
    }

    .user-calendar-time {
        flex: 0 0 auto;
        opacity: 0.9;
    }

    .user-calendar-title {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    body[data-theme="dark"] .calendar-panel,
    body[data-theme="dark"] .calendar-side-panel {
        background: #111827;
        border-color: #263244;
    }

    body[data-theme="dark"] .event-card {
        background: #172033;
        border-color: #263244;
    }

    body[data-theme="dark"] .fc,
    body[data-theme="dark"] .fc .fc-toolbar-title {
        color: #e5eefb;
    }

    body[data-theme="dark"] .fc .fc-button-primary,
    body[data-theme="dark"] .mini-month {
        background: #172033;
        color: #e5eefb;
        border-color: #263244;
    }

    body[data-theme="dark"] .fc-event {
        background: rgba(24, 32, 48, 0.96) !important;
        border-color: rgba(255,255,255,0.14) !important;
        color: #e5eefb !important;
    }

    body[data-theme="dark"] .fc-h-event .fc-event-main,
    body[data-theme="dark"] .user-calendar-event {
        color: #e5eefb !important;
    }

    @media (max-width: 1100px) {
        .calendar-user-grid {
            grid-template-columns: 1fr;
        }

        .calendar-side-panel {
            position: static;
            max-height: none;
        }
    }

    @media (max-width: 700px) {
        .calendar-user-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .calendar-user-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .calendar-panel {
            min-height: 620px;
            border-radius: 8px;
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
            <div class="calendar-user-icon"><i class="fas fa-calendar-days"></i></div>
            <div>
                <h1>Parish Calendar</h1>
                <p>View public parish schedules, sacraments, blessings, announcements, and feast day events clearly.</p>
            </div>
        </div>
        <div class="calendar-user-actions">
            <a href="request-service.php" class="btn btn-primary"><i class="fas fa-church"></i> Request Sacramental Service</a>
        </div>
    </div>

    <div class="calendar-user-grid">
        <aside class="calendar-side-panel">
            <section class="calendar-side-section">
                <h2><i class="fas fa-filter"></i> Find Schedules</h2>
                <input class="mini-month" type="month" id="miniMonth" value="<?php echo date('Y-m'); ?>">
                <input type="search" class="form-control" id="calendarSearch" placeholder="Search parish calendar">
                <select class="form-select" id="categoryFilter">
                    <option value="all">All categories</option>
                    <option value="event">Events</option>
                    <option value="mass">Mass / Public Schedule</option>
                    <option value="monthly_mass">Monthly Mass</option>
                    <option value="sacramental">Sacramental Services</option>
                    <option value="patronal_fiesta">Patronal Fiesta</option>
                    <option value="blessing">Blessings</option>
                    <option value="reservation">Approved bookings</option>
                    <option value="announcement">Announcements</option>
                    <option value="meeting">Meetings</option>
                </select>
                <select class="form-select" id="statusFilter">
                    <option value="all">All statuses</option>
                    <option value="upcoming">Upcoming</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="finished">Finished</option>
                </select>
            </section>

            <section class="calendar-side-section">
                <h2><i class="fas fa-circle-info"></i> Legend</h2>
                <div class="calendar-legend">
                    <div class="legend-item"><span class="legend-dot" style="background:#1a73e8"></span> Parish Event</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#34a853"></span> Mass / Public Schedule</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#0f9d58"></span> Monthly Mass</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#a142f4"></span> Sacramental Services</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#c026d3"></span> Patronal Fiesta</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#d7ad43"></span> Blessing</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#fbbc04"></span> Announcement</div>
                </div>
            </section>

            <section class="calendar-side-section mb-0">
                <div class="upcoming-header">
                    <h2><i class="fas fa-clock"></i> Upcoming</h2>
                    <span class="badge bg-primary"><?php echo count($upcoming_events); ?></span>
                </div>
                <div class="event-stack">
                    <?php if (!empty($upcoming_events)): ?>
                        <?php foreach ($upcoming_events as $event): ?>
                            <button type="button" class="event-card text-start" style="--event-color: <?php echo e($event['color_label'] ?: '#1a73e8'); ?>"
                                    data-title="<?php echo e($event['title']); ?>"
                                    data-date="<?php echo e(formatDate($event['event_date'])); ?>"
                                    data-time="<?php echo e(formatTime($event['start_time'])); ?><?php echo !empty($event['end_time']) ? ' - ' . e(formatTime($event['end_time'])) : ''; ?>"
                                    data-location="<?php echo e($event['location'] ?: 'Parish'); ?>"
                                    data-category="<?php echo e(ucfirst(str_replace('_', ' ', $event['category']))); ?>"
                                    data-description="<?php echo e($event['description']); ?>">
                                <strong><?php echo e($event['title']); ?></strong>
                                <span><?php echo e(formatDate($event['event_date'])); ?> at <?php echo e(formatTime($event['start_time'])); ?></span><br>
                                <small><?php echo e(ucfirst(str_replace('_', ' ', $event['category']))); ?> &bull; <?php echo e($event['location'] ?: 'Parish'); ?></small>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-calendar-plus fa-2x mb-2"></i>
                            <p class="mb-0">No upcoming public schedules yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>

        <section class="calendar-panel">
            <div class="calendar-loading" id="calendarLoading"></div>
            <div id="userCalendar"></div>
            <div class="text-muted small mt-3" id="calendarLiveStatus">
                <i class="fas fa-rotate"></i> Live calendar updates every 10 seconds.
            </div>
        </section>
    </div>
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
                <button type="button" class="btn btn-outline-primary" id="remindBtn"><i class="fas fa-bell"></i> Remind Me</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
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

// Filters Function - Documents this helper's role in the parish management workflow.
function filters() {
    const params = new URLSearchParams();
    const q = document.getElementById('calendarSearch').value.trim();
    const category = document.getElementById('categoryFilter').value;
    const status = document.getElementById('statusFilter').value;
    if (q) params.set('q', q);
    if (category !== 'all') params.set('category', category);
    if (status !== 'all') params.set('status', status);
    return params;
}

// Event Label Function - Documents this helper's role in the parish management workflow.
function eventLabel(value) {
    return String(value || 'Schedule').replace(/_/g, ' ').replace(/\b\w/g, function(char) {
        return char.toUpperCase();
    });
}

// Show Details Function - Documents this helper's role in the parish management workflow.
function showDetails(data) {
    selectedReminder = data;
    document.getElementById('detailsTitle').textContent = data.title;
    document.getElementById('detailsBody').innerHTML = `
        <div class="d-grid gap-2">
            <div><strong>When:</strong> ${data.when}</div>
            <div><strong>Location:</strong> ${data.location || 'Parish'}</div>
            <div><strong>Category:</strong> ${data.category || 'Schedule'}</div>
            <div><strong>Status:</strong> ${data.status || 'Upcoming'}</div>
            <p class="mb-0">${data.description || ''}</p>
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
        dayMaxEvents: isMobileCalendar ? 2 : 5,
        eventTimeFormat: {
            hour: 'numeric',
            minute: '2-digit',
            meridiem: 'short'
        },
        headerToolbar: isMobileCalendar ? {
            left: 'prev',
            center: 'title',
            right: 'next'
        } : {
            left: 'prev,next',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        footerToolbar: isMobileCalendar ? {
            left: 'today',
            center: '',
            right: 'dayGridMonth,listWeek'
        } : false,
        buttonText: {
            month: 'Month',
            week: 'Week',
            day: 'Day',
            list: 'Agenda'
        },
        eventContent: function(arg) {
            const timeText = arg.timeText ? `<span class="user-calendar-time">${arg.timeText}</span>` : '';
            const color = arg.event.backgroundColor || arg.event.borderColor || '#1a73e8';
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
                    document.getElementById('calendarLiveStatus').innerHTML = '<i class="fas fa-check-circle"></i> Updated ' + new Date().toLocaleTimeString();
                })
                .catch(error => {
                    document.getElementById('calendarLiveStatus').innerHTML = '<i class="fas fa-triangle-exclamation"></i> Unable to load calendar. Please refresh.';
                    failureCallback(error);
                });
        },
        loading: function(isLoading) {
            loading.classList.toggle('active', isLoading);
        },
        eventClick: function(info) {
            const props = info.event.extendedProps;
            const when = info.event.start.toLocaleString() + (info.event.end ? ' - ' + info.event.end.toLocaleTimeString() : '');
            showDetails({
                title: info.event.title,
                when,
                location: props.location || 'Parish',
                category: eventLabel(props.category || 'Schedule'),
                status: eventLabel(props.status || 'upcoming'),
                description: props.description || '',
                start: info.event.start
            });
        }
    });
    calendar.render();
    setInterval(() => calendar.refetchEvents(), 10000);
    window.addEventListener('focus', () => calendar.refetchEvents());
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

document.getElementById('remindBtn').addEventListener('click', function() {
    if (!selectedReminder) {
        return;
    }

    const reminders = JSON.parse(localStorage.getItem('parishCalendarReminders') || '[]');
    reminders.push({
        title: selectedReminder.title,
        when: selectedReminder.when,
        savedAt: new Date().toISOString()
    });
    localStorage.setItem('parishCalendarReminders', JSON.stringify(reminders.slice(-25)));
    this.innerHTML = '<i class="fas fa-check"></i> Reminder Saved';
    setTimeout(() => this.innerHTML = '<i class="fas fa-bell"></i> Remind Me', 1800);
});
</script>

<?php include '../templates/footer.php'; ?>
