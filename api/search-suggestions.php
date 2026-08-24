<?php
/**
 * Search Suggestions API - Provides autocomplete suggestions across parish records and requests.
 */
header('Content-Type: application/json');

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();

$query = trim($_GET['q'] ?? '');
$scope = trim($_GET['scope'] ?? '');
$role = hasPermission('admin.access') ? 'admin' : 'user';
$can_users = hasPermission('users.view');
$can_requests = hasAnyPermission(['requests.view', 'requests.manage']);
$can_reservations = hasPermission('reservations.manage');
$can_calendar = hasPermission('calendar.manage');
$can_announcements = hasPermission('announcements.manage');
$can_records = hasAnyPermission(['records.view', 'records.manage']);
$user_id = intval($_SESSION['user_id'] ?? 0);
$suggestions = [];

// Suggestion Table Exists Function - Documents this helper's role in the parish management workflow.
function suggestionTableExists($conn, $table) {
    return in_array($table, ['users','requests','reservations','schedule_events','announcements','baptism_records','first_communion_records','confirmation_records','marriage_records','funeral_records'], true);
}

// Suggestion Column Exists Function - Documents this helper's role in the parish management workflow.
function suggestionColumnExists($conn, $table, $column) {
    $columns=[
        'requests'=>['deleted_at'], 'announcements'=>['deleted_at'],
        'baptism_records'=>['fullname','registry_no','parents','godparents','priest'],
        'first_communion_records'=>['fullname','registry_no','parents','priest','sponsor'],
        'confirmation_records'=>['fullname','registry_no','parents','godparents','priest'],
        'marriage_records'=>['groom_name','registry_no','bride_name','priest','witnesses'],
        'funeral_records'=>['fullname','registry_no','cemetery','priest','cause_of_death']
    ];
    return in_array($column,$columns[$table]??[],true);
}

// Add Suggestion Function - Documents this helper's role in the parish management workflow.
function addSuggestion(&$suggestions, $label, $meta, $url, $icon = 'fa-search') {
    $key = strtolower($label . '|' . $url);
    foreach ($suggestions as $suggestion) {
        if ($suggestion['_key'] === $key) {
            return;
        }
    }

    $suggestions[] = [
        '_key' => $key,
        'label' => $label,
        'meta' => $meta,
        'url' => $url,
        'icon' => $icon
    ];
}

if ($query !== '') {
    $like = '%' . $query . '%';

    if ($can_reservations && $scope === 'reservations' && suggestionTableExists($conn, 'reservations') && suggestionTableExists($conn, 'users')) {
        $stmt = $conn->prepare("
            SELECT u.fullname, u.email, r.reservation_type, r.event_date, r.status
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            WHERE u.fullname LIKE ? OR u.email LIKE ? OR r.reservation_type LIKE ? OR r.status LIKE ? OR r.event_details LIKE ? OR r.admin_notes LIKE ?
            ORDER BY
                CASE WHEN u.fullname LIKE ? THEN 0 ELSE 1 END,
                u.fullname ASC,
                r.event_date DESC
            LIMIT 8
        ");
        if ($stmt) {
            $starts_like = $query . '%';
            $stmt->bind_param('sssssss', $like, $like, $like, $like, $like, $like, $starts_like);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                addSuggestion(
                    $suggestions,
                    $row['fullname'],
                    'Reservation - ' . ucfirst(str_replace('_', ' ', $row['reservation_type'])) . ' - ' . formatDate($row['event_date']) . ' - ' . ucfirst($row['status']),
                    '../admin/manage-reservations.php?q=' . urlencode($row['fullname']),
                    'fa-calendar-check'
                );
            }
            $stmt->close();
        }

        $clean = array_map(function($item) {
            unset($item['_key']);
            return $item;
        }, array_slice($suggestions, 0, 8));

        echo json_encode(['success' => true, 'suggestions' => $clean]);
        exit;
    }

    if ($can_users && suggestionTableExists($conn, 'users')) {
        $stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE role = 'user' AND (fullname LIKE ? OR email LIKE ?) ORDER BY fullname ASC LIMIT 6");
        if ($stmt) {
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                addSuggestion($suggestions, $row['fullname'], 'Parishioner - ' . $row['email'], '../admin/manage-users.php?q=' . urlencode($row['fullname']), 'fa-user');
            }
            $stmt->close();
        }
    }

    if (suggestionTableExists($conn, 'requests')) {
        $deleted_filter = suggestionColumnExists($conn, 'requests', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
        if ($can_requests) {
            $stmt = $conn->prepare("SELECT request_id, reference_number, request_type, status FROM requests WHERE (reference_number LIKE ? OR request_type LIKE ? OR status LIKE ? OR description LIKE ?)$deleted_filter ORDER BY date_requested DESC LIMIT 6");
            if ($stmt) {
                $stmt->bind_param('ssss', $like, $like, $like, $like);
            }
        } else {
            $stmt = $conn->prepare("SELECT request_id, reference_number, request_type, status FROM requests WHERE user_id = ? AND (reference_number LIKE ? OR request_type LIKE ? OR status LIKE ? OR description LIKE ?)$deleted_filter ORDER BY date_requested DESC LIMIT 6");
            if ($stmt) {
                $stmt->bind_param('issss', $user_id, $like, $like, $like, $like);
            }
        }

        if (isset($stmt) && $stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $label = ($row['reference_number'] ?: 'Request') . ' - ' . ucfirst(str_replace('_', ' ', $row['request_type']));
                $url = $can_requests
                    ? '../admin/process-request.php?id=' . intval($row['request_id'])
                    : '../users/view-request.php?id=' . intval($row['request_id']);
                addSuggestion($suggestions, $label, 'Request - ' . ucfirst($row['status']), $url, 'fa-file-lines');
            }
            $stmt->close();
            unset($stmt);
        }
    }

    if ($can_reservations && suggestionTableExists($conn, 'reservations')) {
        $stmt = $conn->prepare("
            SELECT r.reservation_type, r.event_date, r.status, u.fullname, u.email
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            WHERE u.fullname LIKE ? OR u.email LIKE ? OR r.reservation_type LIKE ? OR r.status LIKE ? OR r.event_details LIKE ? OR r.admin_notes LIKE ?
            ORDER BY r.event_date DESC
            LIMIT 6
        ");
        if ($stmt) {
            $stmt->bind_param('ssssss', $like, $like, $like, $like, $like, $like);
        }

        if (isset($stmt) && $stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['fullname'])) {
                    $url = '../admin/manage-reservations.php?q=' . urlencode($row['fullname']);
                    addSuggestion(
                        $suggestions,
                        $row['fullname'],
                        'Reservation - ' . ucfirst(str_replace('_', ' ', $row['reservation_type'])) . ' - ' . formatDate($row['event_date']) . ' - ' . ucfirst($row['status']),
                        $url,
                        'fa-calendar-check'
                    );
                }
            }
            $stmt->close();
            unset($stmt);
        }
    }

    if (suggestionTableExists($conn, 'schedule_events')) {
        $visibility = $can_calendar ? '' : " AND visibility = 'public' AND approval_status = 'approved'";
        $stmt = $conn->prepare("SELECT title, event_date, start_time, location FROM schedule_events WHERE status != 'cancelled'$visibility AND (title LIKE ? OR description LIKE ? OR location LIKE ?) ORDER BY event_date ASC LIMIT 5");
        if ($stmt) {
            $stmt->bind_param('sss', $like, $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $url = $can_calendar ? '../admin/manage-calendar.php' : '../users/view-schedule.php';
                addSuggestion($suggestions, $row['title'], 'Schedule - ' . formatDate($row['event_date']) . ' ' . formatTime($row['start_time']), $url, 'fa-calendar-days');
            }
            $stmt->close();
        }
    }

    if (suggestionTableExists($conn, 'announcements')) {
        $deleted_filter = suggestionColumnExists($conn, 'announcements', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
        $stmt = $conn->prepare("SELECT title, type, published_date FROM announcements WHERE status = 'active'$deleted_filter AND (title LIKE ? OR content LIKE ?) ORDER BY published_date DESC LIMIT 5");
        if ($stmt) {
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $url = $can_announcements ? '../admin/manage-announcements.php?q=' . urlencode($query) : '../users/announcements.php?q=' . urlencode($query);
                addSuggestion($suggestions, $row['title'], ucfirst($row['type']) . ' - ' . formatDate($row['published_date']), $url, 'fa-bullhorn');
            }
            $stmt->close();
        }
    }

    if ($can_records) {
        $record_sources = [
            [
                'table' => 'baptism_records',
                'name_column' => 'fullname',
                'extra_columns' => ['registry_no', 'parents', 'godparents', 'priest'],
                'meta' => 'Baptism Record',
                'url' => '../admin/baptism-records.php?search=',
                'icon' => 'fa-water'
            ],
            [
                'table' => 'first_communion_records',
                'name_column' => 'fullname',
                'extra_columns' => ['registry_no', 'parents', 'priest', 'sponsor'],
                'meta' => 'First Communion Record',
                'url' => '../admin/communion-records.php?search=',
                'icon' => 'fa-bread-slice'
            ],
            [
                'table' => 'confirmation_records',
                'name_column' => 'fullname',
                'extra_columns' => ['registry_no', 'confirmation_name', 'sponsor', 'bishop_priest'],
                'meta' => 'Confirmation Record',
                'url' => '../admin/confirmation-records.php?search=',
                'icon' => 'fa-dove'
            ],
            [
                'table' => 'marriage_records',
                'name_column' => 'husband_name',
                'extra_columns' => ['registry_no', 'wife_name', 'husband_parents', 'wife_parents', 'sponsors', 'witnesses_residence', 'officiating_priest'],
                'meta' => 'Marriage Record',
                'url' => '../admin/marriage-records.php?search=',
                'icon' => 'fa-ring'
            ],
            [
                'table' => 'funeral_records',
                'name_column' => 'deceased_name',
                'extra_columns' => ['registry_no', 'family_name', 'cause_of_death', 'place_of_burial', 'minister', 'remarks'],
                'meta' => 'Funeral Record',
                'url' => '../admin/funeral-records.php?search=',
                'icon' => 'fa-book-open'
            ]
        ];

        foreach ($record_sources as $source) {
            if (!suggestionTableExists($conn, $source['table']) || !suggestionColumnExists($conn, $source['table'], $source['name_column'])) {
                continue;
            }

            $columns = [$source['name_column']];
            foreach ($source['extra_columns'] as $column) {
                if (suggestionColumnExists($conn, $source['table'], $column)) {
                    $columns[] = $column;
                }
            }

            $where_parts = array_map(function($column) {
                return "`$column` LIKE ?";
            }, $columns);
            $select_columns = implode(', ', array_map(function($column) {
                return "`$column`";
            }, $columns));
            $type_string = str_repeat('s', count($columns));
            $values = array_fill(0, count($columns), $like);
            $table = $source['table'];

            $stmt = $conn->prepare("SELECT $select_columns FROM `$table` WHERE " . implode(' OR ', $where_parts) . " ORDER BY `{$source['name_column']}` ASC LIMIT 5");
            if (!$stmt) {
                continue;
            }

            $stmt->bind_param($type_string, ...$values);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $label = $row[$source['name_column']] ?? '';
                if ($source['table'] === 'marriage_records' && !empty($row['wife_name'])) {
                    $label .= ' / ' . $row['wife_name'];
                }
                if ($label === '') {
                    $label = $source['meta'];
                }
                $registry = !empty($row['registry_no']) ? ' - Record #' . $row['registry_no'] : '';
                addSuggestion($suggestions, $label, $source['meta'] . $registry, $source['url'] . urlencode($label), $source['icon']);
            }
            $stmt->close();
        }
    }
}

$clean = array_map(function($item) {
    unset($item['_key']);
    return $item;
}, array_slice($suggestions, 0, 8));

echo json_encode(['success' => true, 'suggestions' => $clean]);
