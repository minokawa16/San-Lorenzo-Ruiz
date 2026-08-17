<?php
/**
 * Calendar Events API - Returns parish schedule events and manages calendar updates for authenticated users.
 */
header('Content-Type: application/json');

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();

if (!ensureScheduleEventsTable($conn)) {
    http_response_code(500);
    echo json_encode(actionResponse(false, 'Unable to prepare calendar table.'));
    exit;
}

$is_admin = isAdmin();
$method = $_SERVER['REQUEST_METHOD'];
$input = [];

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }
}

// API Response Helper - Sends JSON payloads with the expected HTTP status code.
function jsonResponse($payload, $status = 200) {
    http_response_code($status);
    if (is_array($payload) && isset($payload['message']) && !isset($payload['status'])) {
        $payload = actionResponse(
            !empty($payload['success']) || !empty($payload['ok']),
            $payload['message'],
            $payload['type'] ?? null,
            $payload
        );
    }
    echo json_encode($payload);
    exit;
}

// Calendar Validation - Cleans and normalizes dates, times, colors, and end-time defaults.
function cleanCalendarValue($value, $max = 255) {
    return substr(trim((string) $value), 0, $max);
}

// Valid Calendar Date Function - Documents this helper's role in the parish management workflow.
function validCalendarDate($date) {
    $dt = DateTime::createFromFormat('Y-m-d', (string) $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

// Valid Calendar Time Function - Documents this helper's role in the parish management workflow.
function validCalendarTime($time) {
    $dt = DateTime::createFromFormat('H:i', (string) $time);
    if ($dt && $dt->format('H:i') === $time) {
        return true;
    }

    $dt = DateTime::createFromFormat('H:i:s', (string) $time);
    return $dt && $dt->format('H:i:s') === $time;
}

// Normalize Calendar Time Function - Documents this helper's role in the parish management workflow.
function normalizeCalendarTime($time) {
    if ($time === null || $time === '') {
        return null;
    }

    return substr((string) $time, 0, 5);
}

// Normalize Calendar Color Function - Documents this helper's role in the parish management workflow.
function normalizeCalendarColor($color) {
    $color = trim((string) $color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1a73e8';
}

// Schedule End Time Function - Documents this helper's role in the parish management workflow.
function scheduleEndTime($start, $end) {
    if (!empty($end)) {
        return substr((string) $end, 0, 5);
    }

    return date('H:i', strtotime(substr((string) $start, 0, 5) . ' +1 hour'));
}

// Schedule Conflict Detection - Prevents overlapping parish events on the same date and time.
function hasScheduleConflict($conn, $date, $start, $end, $exclude_id = 0) {
    $effective_end = scheduleEndTime($start, $end);
    $exclude_id = intval($exclude_id);
    $items = [];

    $sql = "SELECT schedule_id, title, start_time, end_time FROM schedule_events
            WHERE event_date = ? AND status != 'cancelled' AND schedule_id != ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['conflict' => false];
    }

    $stmt->bind_param('si', $date, $exclude_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    foreach ($items as $item) {
        $item_start = substr((string) $item['start_time'], 0, 5);
        $item_end = scheduleEndTime($item['start_time'], $item['end_time']);
        if ($start < $item_end && $effective_end > $item_start) {
            return [
                'conflict' => true,
                'message' => 'Schedule overlaps with "' . $item['title'] . '" at ' . formatTime($item_start) . '.'
            ];
        }
    }

    return ['conflict' => false];
}

// Event Serialization - Converts database rows into FullCalendar-compatible event objects.
function buildScheduleEvent($row, $instance_date = null, $editable = false) {
    $date = $instance_date ?: $row['event_date'];
    $start = $date . 'T' . substr((string) $row['start_time'], 0, 5) . ':00';
    $end_time = !empty($row['end_time']) ? substr((string) $row['end_time'], 0, 5) . ':00' : null;
    $is_instance = $instance_date !== null && $instance_date !== $row['event_date'];
    $id = 'schedule-' . $row['schedule_id'] . ($is_instance ? '-' . $date : '');

    return [
        'id' => $id,
        'title' => $row['title'],
        'start' => $start,
        'end' => $end_time ? $date . 'T' . $end_time : null,
        'color' => $row['color_label'] ?: '#1a73e8',
        'editable' => $editable && !$is_instance,
        'extendedProps' => [
            'schedule_id' => intval($row['schedule_id']),
            'description' => $row['description'],
            'location' => $row['location'],
            'category' => $row['category'],
            'priority' => $row['priority'],
            'status' => $row['status'],
            'visibility' => $row['visibility'],
            'approval_status' => $row['approval_status'],
            'recurrence_rule' => $row['recurrence_rule'],
            'assigned_personnel' => $row['assigned_personnel'],
            'reminder_minutes' => intval($row['reminder_minutes']),
            'notify_email' => intval($row['notify_email']),
            'notify_sms' => intval($row['notify_sms']),
            'source_type' => $row['source_type'] ?? 'schedule',
            'source_id' => intval($row['source_id'] ?? 0),
            'read_only' => !$editable || $is_instance,
            'instance_date' => $date
        ]
    ];
}

// Recurring Events - Expands daily, weekly, and monthly schedule rules into visible event instances.
function addRecurringScheduleEvents(&$events, $row, $range_start, $range_end, $editable) {
    $rule = $row['recurrence_rule'] ?: 'none';
    if ($rule === 'none') {
        if ($row['event_date'] >= $range_start && $row['event_date'] <= $range_end) {
            $events[] = buildScheduleEvent($row, null, $editable);
        }
        return;
    }

    $intervals = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'];
    if (!isset($intervals[$rule])) {
        return;
    }

    $cursor = new DateTime($row['event_date']);
    $end = new DateTime($range_end);
    $start = new DateTime($range_start);
    $count = 0;

    while ($cursor <= $end && $count < 370) {
        $date = $cursor->format('Y-m-d');
        if ($cursor >= $start) {
            $events[] = buildScheduleEvent($row, $date, $editable);
        }
        $cursor->modify($intervals[$rule]);
        $count++;
    }
}

// Calendar Notifications - Sends parish schedule update alerts to active parishioner accounts.
function notifyCalendarUsers($conn, $title, $message, $send_email = true, $send_sms = true) {
    $result = $conn->query("SELECT id FROM users WHERE role = 'user' AND status = 'active'");
    while ($result && $user = $result->fetch_assoc()) {
        $user_id = intval($user['id']);
        if (createNotification($conn, $user_id, $title, $message, false, 'schedules')) {
            dispatchNotificationDelivery($conn, $user_id, $title, $message, 'schedules', [
                'email' => (bool) $send_email,
                'sms' => (bool) $send_sms
            ]);
        }
    }
}

// GET Route - Returns visible calendar events for the requested date range and filters.
if ($method === 'GET') {
    syncApprovedRequestCalendarBacklog($conn, $_SESSION['user_id'] ?? 0);

    $start = $_GET['start'] ?? date('Y-m-01');
    $end = $_GET['end'] ?? date('Y-m-t');
    $search = cleanCalendarValue($_GET['q'] ?? '', 100);
    $category = cleanCalendarValue($_GET['category'] ?? 'all', 50);
    $schedule_category = $category;
    if ($category === 'monthly_schedule' || $category === 'mass_schedule') {
        $schedule_category = 'monthly_mass';
    } elseif ($category === 'patronal_fiesta_schedule') {
        $schedule_category = 'patronal_fiesta';
    }
    $status = cleanCalendarValue($_GET['status'] ?? 'all', 50);
    $events = [];

    if (!validCalendarDate(substr($start, 0, 10)) || !validCalendarDate(substr($end, 0, 10))) {
        jsonResponse(['success' => false, 'message' => 'Invalid date range.'], 422);
    }

    $range_start = substr($start, 0, 10);
    $range_end = substr($end, 0, 10);
    $where = ["(event_date BETWEEN ? AND ? OR (recurrence_rule != 'none' AND event_date <= ?))"];
    $types = 'sss';
    $params = [$range_start, $range_end, $range_end];

    if (!$is_admin) {
        $where[] = "visibility = 'public'";
        $where[] = "approval_status = 'approved'";
        $where[] = "status != 'cancelled'";
    }

    if ($search !== '') {
        $where[] = "(title LIKE ? OR description LIKE ? OR location LIKE ? OR assigned_personnel LIKE ?)";
        $search_like = '%' . $search . '%';
        $types .= 'ssss';
        array_push($params, $search_like, $search_like, $search_like, $search_like);
    }

    if ($schedule_category !== 'all' && $schedule_category !== '') {
        $where[] = "category = ?";
        $types .= 's';
        $params[] = $schedule_category;
    }

    if ($status !== 'all' && $status !== '') {
        $where[] = "status = ?";
        $types .= 's';
        $params[] = $status;
    }

    $sql = "SELECT * FROM schedule_events WHERE " . implode(' AND ', $where) . " ORDER BY event_date ASC, start_time ASC LIMIT 1200";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Unable to load events.'], 500);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        addRecurringScheduleEvents($events, $row, $range_start, $range_end, $is_admin);
    }
    $stmt->close();

    if ($category === 'all' || $category === '' || $category === 'reservation') {
        $reservationWhere = ["status = 'approved'", "event_date BETWEEN ? AND ?"];
        $reservationTypes = 'ss';
        $reservationParams = [$range_start, $range_end];

        if ($search !== '') {
            $reservationWhere[] = "(reservation_type LIKE ? OR event_details LIKE ?)";
            $search_like = '%' . $search . '%';
            $reservationTypes .= 'ss';
            array_push($reservationParams, $search_like, $search_like);
        }

        $reservationSql = "SELECT reservation_id, reservation_type, event_date, event_time, status
                           FROM reservations
                           WHERE " . implode(' AND ', $reservationWhere) . "
                           AND NOT EXISTS (
                               SELECT 1 FROM schedule_events se
                               WHERE se.source_type = 'reservation'
                               AND se.source_id = reservations.reservation_id
                               AND se.status != 'cancelled'
                           )
                           ORDER BY event_date ASC, event_time ASC LIMIT 300";
        $stmt = $conn->prepare($reservationSql);
        if ($stmt) {
            $stmt->bind_param($reservationTypes, ...$reservationParams);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && $row = $result->fetch_assoc()) {
                $title = ucfirst(str_replace('_', ' ', $row['reservation_type'])) . ' Reservation';
                $start_time = $row['event_time'] ? substr((string) $row['event_time'], 0, 5) : '08:00';
                $events[] = [
                    'id' => 'reservation-' . $row['reservation_id'],
                    'title' => $title,
                    'start' => $row['event_date'] . 'T' . $start_time . ':00',
                    'color' => '#188038',
                    'editable' => false,
                    'extendedProps' => [
                        'category' => 'reservation',
                        'status' => 'upcoming',
                        'priority' => 'normal',
                        'source_type' => 'reservation',
                        'read_only' => true,
                        'description' => 'Approved parish reservation.',
                        'location' => 'Parish'
                    ]
                ];
            }
            $stmt->close();
        }
    }

    if (tableExists($conn, 'announcements') && !columnExists($conn, 'announcements', 'event_date')) {
        $conn->query("ALTER TABLE announcements ADD COLUMN event_date DATE NULL AFTER expiry_date");
    }

    $announcement_calendar_types = ['announcement', 'monthly_schedule', 'mass_schedule', 'parish_event', 'patronal_fiesta_schedule'];
    if (in_array($category, array_merge(['all', '', 'announcement', 'event', 'schedule', 'mass', 'monthly_mass', 'patronal_fiesta'], $announcement_calendar_types), true)) {
        $announcementWhere = ["status = 'active'", "deleted_at IS NULL", "type IN ('announcement', 'monthly_schedule', 'mass_schedule', 'parish_event', 'patronal_fiesta_schedule')", "DATE(published_date) BETWEEN ? AND ?"];
        $announcementTypes = 'ss';
        $announcementParams = [$range_start, $range_end];

        if (in_array($category, $announcement_calendar_types, true)) {
            $announcementWhere[] = "type = ?";
            $announcementTypes .= 's';
            $announcementParams[] = $category;
        } elseif ($category === 'event') {
            $announcementWhere[] = "type = 'parish_event'";
        } elseif ($category === 'monthly_mass') {
            $announcementWhere[] = "type IN ('monthly_schedule', 'mass_schedule')";
        } elseif ($category === 'mass') {
            $announcementWhere[] = "type = 'mass_schedule'";
        } elseif ($category === 'patronal_fiesta') {
            $announcementWhere[] = "type = 'patronal_fiesta_schedule'";
        } elseif ($category === 'schedule') {
            $announcementWhere[] = "type IN ('monthly_schedule', 'mass_schedule', 'patronal_fiesta_schedule')";
        }

        if ($search !== '') {
            $announcementWhere[] = "(title LIKE ? OR content LIKE ?)";
            $search_like = '%' . $search . '%';
            $announcementTypes .= 'ss';
            array_push($announcementParams, $search_like, $search_like);
        }

        $announcementSql = "SELECT announcement_id, title, content, type, published_date, event_date
                            FROM announcements
                            WHERE " . implode(' AND ', $announcementWhere) . "
                            ORDER BY published_date DESC LIMIT 200";
        $stmt = $conn->prepare($announcementSql);
        if ($stmt) {
            $stmt->bind_param($announcementTypes, ...$announcementParams);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && $row = $result->fetch_assoc()) {
                $announcement_category = $row['type'];
                $announcement_color = '#fbbc04';
                if ($row['type'] === 'monthly_schedule' || $row['type'] === 'mass_schedule') {
                    $announcement_category = $row['type'] === 'mass_schedule' ? 'mass' : 'monthly_mass';
                    $announcement_color = $row['type'] === 'mass_schedule' ? '#34a853' : '#0f9d58';
                } elseif ($row['type'] === 'patronal_fiesta_schedule') {
                    $announcement_category = 'patronal_fiesta';
                    $announcement_color = '#c026d3';
                } elseif ($row['type'] === 'parish_event') {
                    $announcement_category = 'event';
                    $announcement_color = '#1a73e8';
                }

                $events[] = [
                    'id' => 'announcement-' . $row['announcement_id'],
                    'title' => $row['title'],
                    'start' => date('Y-m-d\T09:00:00', strtotime($row['event_date'] ?: $row['published_date'])),
                    'color' => $announcement_color,
                    'editable' => false,
                    'extendedProps' => [
                        'category' => $announcement_category,
                        'status' => 'upcoming',
                        'priority' => 'normal',
                        'source_type' => 'announcement',
                        'read_only' => true,
                        'description' => $row['content'],
                        'location' => 'Parish'
                    ]
                ];
            }
            $stmt->close();
        }
    }

    echo json_encode($events);
    exit;
}

if (!$is_admin) {
    jsonResponse(['success' => false, 'message' => 'Only admins can change schedules.'], 403);
}

// POST Route - Creates a new parish schedule event after admin validation.
if ($method === 'POST') {
    $title = cleanCalendarValue($input['title'] ?? '', 200);
    $description = cleanCalendarValue($input['description'] ?? '', 5000);
    $event_date = cleanCalendarValue($input['event_date'] ?? '', 10);
    $start_time = normalizeCalendarTime($input['start_time'] ?? '');
    $end_time = normalizeCalendarTime($input['end_time'] ?? null);
    $location = cleanCalendarValue($input['location'] ?? '', 150);
    $category = cleanCalendarValue($input['category'] ?? 'event', 50);
    $priority = cleanCalendarValue($input['priority'] ?? 'normal', 20);
    $color_label = normalizeCalendarColor($input['color_label'] ?? '#1a73e8');
    $recurrence_rule = cleanCalendarValue($input['recurrence_rule'] ?? 'none', 100);
    $assigned_personnel = cleanCalendarValue($input['assigned_personnel'] ?? '', 150);
    $visibility = ($input['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
    $approval_status = in_array(($input['approval_status'] ?? 'approved'), ['pending', 'approved', 'rejected'], true) ? $input['approval_status'] : 'approved';
    $status = in_array(($input['status'] ?? 'upcoming'), ['upcoming', 'ongoing', 'finished', 'cancelled'], true) ? $input['status'] : 'upcoming';
    $reminder_minutes = max(0, intval($input['reminder_minutes'] ?? 30));
    $notify_email = !empty($input['notify_email']) ? 1 : 0;
    $notify_sms = !empty($input['notify_sms']) ? 1 : 0;
    $created_by = intval($_SESSION['user_id']);

    if ($title === '' || !validCalendarDate($event_date) || !$start_time || !validCalendarTime($start_time)) {
        jsonResponse(['success' => false, 'message' => 'Title, date, and start time are required.'], 422);
    }

    if ($end_time && (!validCalendarTime($end_time) || $end_time <= $start_time)) {
        jsonResponse(['success' => false, 'message' => 'End time must be later than start time.'], 422);
    }

    $conflict = hasScheduleConflict($conn, $event_date, $start_time, $end_time);
    if ($conflict['conflict']) {
        jsonResponse(['success' => false, 'message' => $conflict['message'], 'conflict' => true], 409);
    }

    $sql = "INSERT INTO schedule_events
            (title, description, event_date, start_time, end_time, location, category, priority, color_label,
             recurrence_rule, assigned_personnel, visibility, approval_status, status, reminder_minutes,
             notify_email, notify_sms, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Unable to save schedule.'], 500);
    }

    $stmt->bind_param(
        'ssssssssssssssiiii',
        $title,
        $description,
        $event_date,
        $start_time,
        $end_time,
        $location,
        $category,
        $priority,
        $color_label,
        $recurrence_rule,
        $assigned_personnel,
        $visibility,
        $approval_status,
        $status,
        $reminder_minutes,
        $notify_email,
        $notify_sms,
        $created_by
    );

    if (!$stmt->execute()) {
        jsonResponse(['success' => false, 'message' => 'Unable to save schedule.'], 500);
    }

    $schedule_id = $stmt->insert_id;
    $stmt->close();

    createAuditLog($conn, $_SESSION['user_id'], 'ADD_SCHEDULE_EVENT', 'schedule_events', $schedule_id);
    if ($visibility === 'public' && $approval_status === 'approved' && ($notify_email || $notify_sms)) {
        notifyCalendarUsers($conn, 'New Parish Schedule', $title . ' is scheduled on ' . formatDate($event_date) . ' at ' . formatTime($start_time) . '.', (bool) $notify_email, (bool) $notify_sms);
    }

    jsonResponse(['success' => true, 'message' => 'Schedule saved.', 'id' => $schedule_id]);
}

// PUT/PATCH Route - Updates an existing schedule event while preserving conflict checks.
if (in_array($method, ['PUT', 'PATCH'], true)) {
    $id = intval($input['schedule_id'] ?? $input['id'] ?? 0);
    if ($id <= 0 && !empty($input['event_id']) && preg_match('/schedule-(\d+)/', $input['event_id'], $matches)) {
        $id = intval($matches[1]);
    }

    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Missing schedule ID.'], 422);
    }

    $current = null;
    $stmt = $conn->prepare("SELECT * FROM schedule_events WHERE schedule_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }

    if (!$current) {
        jsonResponse(['success' => false, 'message' => 'Schedule not found.'], 404);
    }

    $title = cleanCalendarValue($input['title'] ?? $current['title'], 200);
    $description = cleanCalendarValue($input['description'] ?? $current['description'], 5000);
    $event_date = cleanCalendarValue($input['event_date'] ?? $current['event_date'], 10);
    $start_time = normalizeCalendarTime($input['start_time'] ?? $current['start_time']);
    $end_time = normalizeCalendarTime(array_key_exists('end_time', $input) ? $input['end_time'] : $current['end_time']);
    $location = cleanCalendarValue($input['location'] ?? $current['location'], 150);
    $category = cleanCalendarValue($input['category'] ?? $current['category'], 50);
    $priority = cleanCalendarValue($input['priority'] ?? $current['priority'], 20);
    $color_label = normalizeCalendarColor($input['color_label'] ?? $current['color_label']);
    $recurrence_rule = cleanCalendarValue($input['recurrence_rule'] ?? $current['recurrence_rule'], 100);
    $assigned_personnel = cleanCalendarValue($input['assigned_personnel'] ?? $current['assigned_personnel'], 150);
    $visibility = ($input['visibility'] ?? $current['visibility']) === 'private' ? 'private' : 'public';
    $approval_status = in_array(($input['approval_status'] ?? $current['approval_status']), ['pending', 'approved', 'rejected'], true) ? ($input['approval_status'] ?? $current['approval_status']) : 'approved';
    $status = in_array(($input['status'] ?? $current['status']), ['upcoming', 'ongoing', 'finished', 'cancelled'], true) ? ($input['status'] ?? $current['status']) : 'upcoming';
    $reminder_minutes = max(0, intval($input['reminder_minutes'] ?? $current['reminder_minutes']));
    $notify_email = !empty($input['notify_email']) ? 1 : 0;
    $notify_sms = !empty($input['notify_sms']) ? 1 : 0;

    if ($title === '' || !validCalendarDate($event_date) || !$start_time || !validCalendarTime($start_time)) {
        jsonResponse(['success' => false, 'message' => 'Title, date, and start time are required.'], 422);
    }

    if ($end_time && (!validCalendarTime($end_time) || $end_time <= $start_time)) {
        jsonResponse(['success' => false, 'message' => 'End time must be later than start time.'], 422);
    }

    if ($status !== 'cancelled') {
        $conflict = hasScheduleConflict($conn, $event_date, $start_time, $end_time, $id);
        if ($conflict['conflict']) {
            jsonResponse(['success' => false, 'message' => $conflict['message'], 'conflict' => true], 409);
        }
    }

    $sql = "UPDATE schedule_events
            SET title = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, location = ?,
                category = ?, priority = ?, color_label = ?, recurrence_rule = ?, assigned_personnel = ?,
                visibility = ?, approval_status = ?, status = ?, reminder_minutes = ?, notify_email = ?, notify_sms = ?
            WHERE schedule_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Unable to update schedule.'], 500);
    }

    $stmt->bind_param(
        'ssssssssssssssiiii',
        $title,
        $description,
        $event_date,
        $start_time,
        $end_time,
        $location,
        $category,
        $priority,
        $color_label,
        $recurrence_rule,
        $assigned_personnel,
        $visibility,
        $approval_status,
        $status,
        $reminder_minutes,
        $notify_email,
        $notify_sms,
        $id
    );

    if (!$stmt->execute()) {
        jsonResponse(['success' => false, 'message' => 'Unable to update schedule.'], 500);
    }

    $stmt->close();
    createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_SCHEDULE_EVENT', 'schedule_events', $id, $current);
    jsonResponse(['success' => true, 'message' => 'Schedule updated.']);
}

// DELETE Route - Cancels schedule events or removes draft entries from the calendar.
if ($method === 'DELETE') {
    $id = intval($input['schedule_id'] ?? $input['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Missing schedule ID.'], 422);
    }

    $stmt = $conn->prepare("DELETE FROM schedule_events WHERE schedule_id = ?");
    if (!$stmt) {
        jsonResponse(['success' => false, 'message' => 'Unable to delete schedule.'], 500);
    }

    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        jsonResponse(['success' => false, 'message' => 'Unable to delete schedule.'], 500);
    }

    $stmt->close();
    createAuditLog($conn, $_SESSION['user_id'], 'DELETE_SCHEDULE_EVENT', 'schedule_events', $id);
    jsonResponse(['success' => true, 'message' => 'Schedule deleted.']);
}

jsonResponse(['success' => false, 'message' => 'Unsupported calendar action.'], 405);
?>
