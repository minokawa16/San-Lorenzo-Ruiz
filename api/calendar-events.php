<?php
/**
 * Calendar Events API - Returns parish schedule events and manages calendar updates for authenticated users.
 */
header('Content-Type: application/json; charset=utf-8');

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();

$is_admin = isAdmin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $submittedToken = is_string($headerToken) && $headerToken !== ''
        ? $headerToken
        : ($input['csrf_token'] ?? ($input[csrfTokenName()] ?? ($_POST[csrfTokenName()] ?? '')));

    if (!verifyCsrfToken($submittedToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your security session has expired. Please refresh the page and try again.'
        ]);
        exit;
    }
}

if (!ensureScheduleEventsTable($conn)) {
    http_response_code(500);
    echo json_encode(actionResponse(false, 'Unable to prepare calendar table.'));
    exit;
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

// Calendar Validation - Cleans and normalizes strings, dates, times, colors, and end-time defaults.
function cleanCalendarValue($value, $max = 255) {
    return substr(trim((string) $value), 0, $max);
}

// Normalize and parse dates into MySQL YYYY-MM-DD format.
function normalizeCalendarDate($date) {
    $date = trim((string) $date);
    if ($date === '') {
        return null;
    }

    // Already YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $parts = explode('-', $date);
        if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return $date;
        }
    }

    // DD/MM/YYYY or DD-MM-YYYY
    if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})$/', $date, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if ($mo <= 12 && $d <= 31 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        } elseif ($d <= 12 && $mo <= 31 && checkdate($d, $mo, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $d, $mo);
        }
    }

    // Parse via strtotime
    $ts = strtotime($date);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return null;
}

function validCalendarDate($date) {
    return normalizeCalendarDate($date) !== null;
}

// Normalize and parse times into MySQL HH:MM:SS format.
function normalizeCalendarTime($time) {
    $time = trim((string) $time);
    if ($time === '') {
        return null;
    }

    // Match 24hr HH:MM or HH:MM:SS
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $time, $m)) {
        return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
    }

    // Match 12hr HH:MM am/pm
    if (preg_match('/^([01]?\d):([0-5]\d)\s*(am|pm)$/i', $time, $m)) {
        $h = (int)$m[1];
        $min = (int)$m[2];
        $ampm = strtolower($m[3]);
        if ($ampm === 'pm' && $h < 12) $h += 12;
        if ($ampm === 'am' && $h === 12) $h = 0;
        return sprintf('%02d:%02d:00', $h, $min);
    }

    $ts = strtotime($time);
    if ($ts !== false) {
        return date('H:i:s', $ts);
    }

    return null;
}

function validCalendarTime($time) {
    return normalizeCalendarTime($time) !== null;
}

// Normalize category codes and user-facing labels
function normalizeCalendarCategory($cat) {
    $c = strtolower(trim((string)$cat));
    $map = [
        'event' => 'event',
        'parish event' => 'event',
        'events' => 'event',
        'mass' => 'mass',
        'mass schedule' => 'mass',
        'mass / public schedule' => 'mass',
        'monthly mass' => 'monthly_mass',
        'monthly_mass' => 'monthly_mass',
        'monthly schedule' => 'monthly_mass',
        'sacramental' => 'sacramental',
        'sacramental services' => 'sacramental',
        'patronal fiesta' => 'patronal_fiesta',
        'patronal_fiesta' => 'patronal_fiesta',
        'patronal fiesta schedule' => 'patronal_fiesta',
        'meeting' => 'meeting',
        'meetings' => 'meeting',
        'task' => 'task',
        'tasks' => 'task',
        'blessing' => 'blessing',
        'blessings' => 'blessing',
        'child blessing' => 'blessing',
        'reservation' => 'reservation',
        'reservations' => 'reservation',
        'announcement' => 'announcement',
        'announcements' => 'announcement'
    ];
    return $map[$c] ?? (preg_replace('/[^a-z0-9_]/', '_', $c) ?: 'event');
}

// Normalize Calendar Color Function
function normalizeCalendarColor($color) {
    $color = trim((string) $color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1a73e8';
}

// Schedule End Time Function
function scheduleEndTime($start, $end) {
    $start_norm = normalizeCalendarTime($start);
    $end_norm = normalizeCalendarTime($end);
    if (!empty($end_norm)) {
        return $end_norm;
    }
    if (!empty($start_norm)) {
        return date('H:i:s', strtotime($start_norm . ' +1 hour'));
    }
    return '09:00:00';
}

// Schedule Conflict Detection - Checks venue/location overlap
function hasScheduleConflict($conn, $date, $start, $end, $location = '', $exclude_id = 0) {
    $date_norm = normalizeCalendarDate($date);
    $start_norm = normalizeCalendarTime($start);
    $effective_end = scheduleEndTime($start, $end);
    $exclude_id = intval($exclude_id);
    $items = [];

    $sql = "SELECT schedule_id, title, start_time, end_time, location FROM schedule_events
            WHERE event_date = ? AND status != 'cancelled' AND schedule_id != ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['conflict' => false];
    }

    $stmt->bind_param('si', $date_norm, $exclude_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    $trimmed_loc = strtolower(trim((string)$location));

    foreach ($items as $item) {
        $item_start = normalizeCalendarTime($item['start_time']);
        $item_end = scheduleEndTime($item['start_time'], $item['end_time']);
        $item_loc = strtolower(trim((string)$item['location']));

        if ($start_norm < $item_end && $effective_end > $item_start) {
            // If both specify a location and locations are identical
            if ($trimmed_loc !== '' && $item_loc !== '' && $trimmed_loc === $item_loc) {
                return [
                    'conflict' => true,
                    'message' => 'Venue conflict: "' . $item['title'] . '" is already scheduled at ' . $item['location'] . ' (' . formatTime($item_start) . ' - ' . formatTime($item_end) . ').'
                ];
            }
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

    $range_start = normalizeCalendarDate(substr($start, 0, 10));
    $range_end = normalizeCalendarDate(substr($end, 0, 10));

    if (!$range_start || !$range_end) {
        jsonResponse(['success' => false, 'message' => 'Invalid date range.'], 422);
    }

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
        error_log("Calendar GET prepare error: " . $conn->error);
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
    $event_date = normalizeCalendarDate($input['event_date'] ?? '');
    $start_time = normalizeCalendarTime($input['start_time'] ?? '');
    $end_time = normalizeCalendarTime($input['end_time'] ?? null);
    $location = cleanCalendarValue($input['location'] ?? '', 150);
    $category = normalizeCalendarCategory($input['category'] ?? 'event');
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

    if ($title === '') {
        jsonResponse(['success' => false, 'message' => 'Please enter a schedule title.'], 422);
    }
    if (!$event_date) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid schedule date.'], 422);
    }
    if (!$start_time) {
        jsonResponse(['success' => false, 'message' => 'Please enter a valid start time.'], 422);
    }
    if ($end_time && $end_time <= $start_time) {
        jsonResponse(['success' => false, 'message' => 'End time must be later than start time.'], 422);
    }

    $conflict = hasScheduleConflict($conn, $event_date, $start_time, $end_time, $location);
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
        error_log("Calendar INSERT prepare error: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Unable to save schedule. Database preparation failed.'], 500);
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
        error_log("Calendar INSERT execute error: " . $stmt->error);
        jsonResponse(['success' => false, 'message' => 'Unable to save schedule. ' . $stmt->error], 500);
    }

    $schedule_id = $stmt->insert_id;
    $stmt->close();

    createAuditLog($conn, $_SESSION['user_id'], 'ADD_SCHEDULE_EVENT', 'schedule_events', $schedule_id);
    if ($visibility === 'public' && $approval_status === 'approved' && ($notify_email || $notify_sms)) {
        notifyCalendarUsers($conn, 'New Parish Schedule', $title . ' is scheduled on ' . formatDate($event_date) . ' at ' . formatTime($start_time) . '.', (bool) $notify_email, (bool) $notify_sms);
    }

    jsonResponse(['success' => true, 'message' => 'Schedule saved successfully.', 'id' => $schedule_id, 'schedule_id' => $schedule_id]);
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
    $event_date = normalizeCalendarDate($input['event_date'] ?? $current['event_date']);
    $start_time = normalizeCalendarTime($input['start_time'] ?? $current['start_time']);
    $end_time = normalizeCalendarTime(array_key_exists('end_time', $input) ? $input['end_time'] : $current['end_time']);
    $location = cleanCalendarValue($input['location'] ?? $current['location'], 150);
    $category = normalizeCalendarCategory($input['category'] ?? $current['category']);
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

    if ($title === '') {
        jsonResponse(['success' => false, 'message' => 'Please enter a schedule title.'], 422);
    }
    if (!$event_date) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid schedule date.'], 422);
    }
    if (!$start_time) {
        jsonResponse(['success' => false, 'message' => 'Please enter a valid start time.'], 422);
    }
    if ($end_time && $end_time <= $start_time) {
        jsonResponse(['success' => false, 'message' => 'End time must be later than start time.'], 422);
    }

    if ($status !== 'cancelled') {
        $conflict = hasScheduleConflict($conn, $event_date, $start_time, $end_time, $location, $id);
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
        error_log("Calendar UPDATE prepare error: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Unable to update schedule. Database preparation failed.'], 500);
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
        error_log("Calendar UPDATE execute error: " . $stmt->error);
        jsonResponse(['success' => false, 'message' => 'Unable to update schedule. ' . $stmt->error], 500);
    }

    $stmt->close();
    createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_SCHEDULE_EVENT', 'schedule_events', $id, $current);
    jsonResponse(['success' => true, 'message' => 'Schedule updated successfully.']);
}

// DELETE Route - Cancels schedule events or removes draft entries from the calendar.
if ($method === 'DELETE') {
    $id = intval($input['schedule_id'] ?? $input['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Missing schedule ID.'], 422);
    }

    $stmt = $conn->prepare("DELETE FROM schedule_events WHERE schedule_id = ?");
    if (!$stmt) {
        error_log("Calendar DELETE prepare error: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Unable to delete schedule.'], 500);
    }

    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        error_log("Calendar DELETE execute error: " . $stmt->error);
        jsonResponse(['success' => false, 'message' => 'Unable to delete schedule. ' . $stmt->error], 500);
    }

    $stmt->close();
    createAuditLog($conn, $_SESSION['user_id'], 'DELETE_SCHEDULE_EVENT', 'schedule_events', $id);
    jsonResponse(['success' => true, 'message' => 'Schedule deleted successfully.']);
}

jsonResponse(['success' => false, 'message' => 'Unsupported calendar action.'], 405);
