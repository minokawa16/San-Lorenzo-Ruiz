<?php
/**
 * BAPTISM RECORDS MANAGEMENT
 * Admin page for managing baptism sacramental records
 * Features: Search, Filter, CRUD, CSV Import/Export, Link to Requests
 */

include '../config/security.php';
include '../includes/session.php';
include '../includes/helpers.php';
include '../database/config.php';
require_once '../services/SacramentalRecordService.php';

// Define BASE_URL if not already defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/ParishSystem/');
}

requireAdmin();
requirePermission('records.manage');

if (!function_exists('fetch_all_assoc')) {
    function fetch_all_assoc($stmt) {
        $rows = array();
        $meta = $stmt->result_metadata();
        if (!$meta) {
            return $rows;
        }

        $fields = $meta->fetch_fields();
        $row = array();
        $bind = array();

        foreach ($fields as $field) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);

        while ($stmt->fetch()) {
            $rows[] = array_map(function ($value) {
                return $value;
            }, $row);
        }

        return $rows;
    }
}

// Baptism Column Exists Function - Documents this helper's role in the parish management workflow.
function baptism_column_exists($conn, $column) {
    return schemaColumnExists($conn, 'baptism_records', (string) $column);
}

// Ensure Baptism Record Book Schema Function - Documents this helper's role in the parish management workflow.
function ensure_baptism_record_book_schema($conn) {
    return requireSchemaColumns($conn, 'baptism_records', [
        'request_id', 'registry_no', 'book_no', 'page_no', 'entry_no', 'birth_place',
        'birth_status', 'parent_address', 'parish_address', 'remarks',
        'parish_priest', 'parish_secretary'
    ], 'baptism records');
}

// Format Baptism Record Date Function - Documents this helper's role in the parish management workflow.
function format_baptism_record_date($date_value, $format = 'M d, Y') {
    if (empty($date_value) || $date_value === '0000-00-00') {
        return 'N/A';
    }

    $timestamp = strtotime($date_value);
    return $timestamp ? date($format, $timestamp) : 'N/A';
}

// Js Value Function - Documents this helper's role in the parish management workflow.
function js_value($value) {
    return htmlspecialchars(json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
}

ensure_baptism_record_book_schema($conn);

// Handle POST actions
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$message = '';
$alert_type = '';

// Phase 8: all mutations pass through the authoritative workflow service.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit', 'archive', 'restore'], true)) {
    requireValidCsrfToken();
    try {
        $records = new SacramentalRecordService($conn);
        $actor = (int)($_SESSION['user_id'] ?? 0);
        if ($action === 'add') {
            $records->create('baptism', $_POST, $actor);
            redirectWithNotification('baptism-records.php', 'Baptism record created.', 'success');
        } elseif ($action === 'edit') {
            $records->requestCorrection('baptism', (int)($_POST['record_id'] ?? 0), $_POST, (string)($_POST['correction_reason'] ?? ''), $actor);
            redirectWithNotification('baptism-records.php', 'Correction submitted for review; the official record was not overwritten.', 'success');
        } elseif ($action === 'archive') {
            $records->archive('baptism', (int)($_POST['record_id'] ?? 0), (string)($_POST['archive_reason'] ?? ''), $actor);
            redirectWithNotification('baptism-records.php', 'Baptism record archived.', 'success');
        } else {
            $records->restore('baptism', (int)($_POST['record_id'] ?? 0), $actor);
            redirectWithNotification('baptism-records.php', 'Baptism record restored.', 'success');
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $alert_type = 'danger';
        $action = null;
    }
}

// Add new baptism record
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $registry_no = trim($_POST['registry_no'] ?? '');
    $book_no = trim($_POST['book_no'] ?? '');
    $page_no = trim($_POST['page_no'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $birth_date = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birth_place = trim($_POST['birth_place'] ?? '');
    $birth_status = trim($_POST['birth_status'] ?? '');
    $parents = trim($_POST['parents'] ?? '');
    $parent_address = trim($_POST['parent_address'] ?? '');
    $baptism_date = !empty($_POST['baptism_date']) ? $_POST['baptism_date'] : null;
    $godparents = trim($_POST['godparents'] ?? '');
    $parish_address = trim($_POST['parish_address'] ?? '');
    $priest = trim($_POST['priest'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $parish_priest = trim($_POST['parish_priest'] ?? '');
    $parish_secretary = trim($_POST['parish_secretary'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $request_id = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;

    if ($fullname && $baptism_date && $parents) {
        $stmt = $conn->prepare("INSERT INTO baptism_records (registry_no, book_no, page_no, fullname, birth_date, birth_place, birth_status, parents, parent_address, baptism_date, godparents, parish_address, priest, remarks, parish_priest, parish_secretary, status, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssssssssssssssi", $registry_no, $book_no, $page_no, $fullname, $birth_date, $birth_place, $birth_status, $parents, $parent_address, $baptism_date, $godparents, $parish_address, $priest, $remarks, $parish_priest, $parish_secretary, $status, $request_id);
            if ($stmt->execute()) {
                $message = "Baptism record added successfully!";
                $alert_type = "success";
            } else {
                $message = "Error adding record: " . $stmt->error;
                $alert_type = "danger";
            }
            $stmt->close();
        }
    } else {
        $message = "Please fill in all required fields.";
        $alert_type = "warning";
    }
}

// Update baptism record
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    $registry_no = trim($_POST['registry_no'] ?? '');
    $book_no = trim($_POST['book_no'] ?? '');
    $page_no = trim($_POST['page_no'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $birth_date = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $birth_place = trim($_POST['birth_place'] ?? '');
    $birth_status = trim($_POST['birth_status'] ?? '');
    $parents = trim($_POST['parents'] ?? '');
    $parent_address = trim($_POST['parent_address'] ?? '');
    $baptism_date = !empty($_POST['baptism_date']) ? $_POST['baptism_date'] : null;
    $godparents = trim($_POST['godparents'] ?? '');
    $parish_address = trim($_POST['parish_address'] ?? '');
    $priest = trim($_POST['priest'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $parish_priest = trim($_POST['parish_priest'] ?? '');
    $parish_secretary = trim($_POST['parish_secretary'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $request_id = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;

    if ($record_id && $fullname && $baptism_date && $parents) {
        $stmt = $conn->prepare("UPDATE baptism_records SET registry_no=?, book_no=?, page_no=?, fullname=?, birth_date=?, birth_place=?, birth_status=?, parents=?, parent_address=?, baptism_date=?, godparents=?, parish_address=?, priest=?, remarks=?, parish_priest=?, parish_secretary=?, status=?, request_id=? WHERE baptism_id=?");
        if ($stmt) {
            $stmt->bind_param("sssssssssssssssssii", $registry_no, $book_no, $page_no, $fullname, $birth_date, $birth_place, $birth_status, $parents, $parent_address, $baptism_date, $godparents, $parish_address, $priest, $remarks, $parish_priest, $parish_secretary, $status, $request_id, $record_id);
            if ($stmt->execute()) {
                $message = "Baptism record updated successfully!";
                $alert_type = "success";
            } else {
                $message = "Error updating record: " . $stmt->error;
                $alert_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Archive baptism record
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    if ($record_id) {
        $stmt = $conn->prepare("UPDATE baptism_records SET status='archived', updated_at=NOW() WHERE baptism_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $record_id);
            if ($stmt->execute()) {
                $message = "Baptism record archived successfully!";
                $alert_type = "success";
            } else {
                $message = "Error archiving record: " . $stmt->error;
                $alert_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Get search and filter parameters
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

// Build query
$where_clauses = array("1=1");
$params = array();
$param_types = "";

if (!empty($search)) {
    $where_clauses[] = "(fullname LIKE ? OR book_no LIKE ? OR page_no LIKE ? OR parents LIKE ? OR parent_address LIKE ? OR godparents LIKE ? OR parish_address LIKE ? OR priest LIKE ? OR birth_place LIKE ? OR parish_priest LIKE ? OR parish_secretary LIKE ?)";
    $search_param = "%$search%";
    for ($i = 0; $i < 11; $i++) {
        $params[] = $search_param;
    }
    $param_types .= "sssssssssss";
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

$where = implode(" AND ", $where_clauses);

// Get total count
$count_query = "SELECT COUNT(*) as count FROM baptism_records WHERE $where";
$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    if (!empty($params)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    $count_stmt->execute();
    $count_rows = fetch_all_assoc($count_stmt);
    $total_records = (int)($count_rows[0]['count'] ?? 0);
    $count_stmt->close();
}

$total_pages = max(1, (int)ceil($total_records / $per_page));
$offset = ($page - 1) * $per_page;

// Get records
$query = "SELECT * FROM baptism_records WHERE $where ORDER BY baptism_date DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$params[] = $per_page;
$params[] = $offset;
$param_types .= "ii";

$records = array();
if ($stmt) {
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $records = fetch_all_assoc($stmt);
    $stmt->close();
}

// Get list of requests for dropdown
$requests_list = array();
$req_stmt = $conn->prepare("SELECT request_id, reference_number FROM requests ORDER BY date_requested DESC");
if ($req_stmt) {
    $req_stmt->execute();
    $requests_list = fetch_all_assoc($req_stmt);
    $req_stmt->close();
}

// Stats for formal metrics banner
$stat_total = 0;
$stat_active = 0;
$stat_archived = 0;
$stat_books = 0;

$stat_res = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived,
    COUNT(DISTINCT NULLIF(TRIM(book_no), '')) as books
FROM baptism_records");
if ($stat_res && $stat_row = $stat_res->fetch_assoc()) {
    $stat_total = (int)($stat_row['total'] ?? 0);
    $stat_active = (int)($stat_row['active'] ?? 0);
    $stat_archived = (int)($stat_row['archived'] ?? 0);
    $stat_books = (int)($stat_row['books'] ?? 0);
}

$page_title = 'Baptism Records';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Sacramental Records' => 'manage-records.php',
    'Baptism Records' => null
];

include '../templates/header.php';
?>
    <style>
        :root {
            --parish-navy: #152238;
            --parish-navy-light: #1E2E4A;
            --parish-gold: #C89B3C;
            --parish-gold-light: #F4EBD7;
            --parish-gold-dark: #8C6427;
            --parish-cream: #FAF8F5;
            --parish-border: #E5E7EB;
            --parish-border-gold: #E8DCBF;
            --parish-ink: #0F172A;
            --parish-muted: #64748B;
            --parish-success: #15803D;
            --parish-success-bg: #ECFDF5;
            --parish-warning: #B45309;
            --parish-warning-bg: #FFFBEB;
            --parish-danger: #B91C1C;
            --parish-danger-bg: #FEF2F2;
        }

        .baptism-registry-shell {
            width: 100%;
            margin-bottom: 30px;
        }

        /* ── Formal Stats Ribbon ────────────────────────── */
        .registry-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .registry-stat-card {
            background: #FFFFFF;
            border: 1px solid var(--parish-border);
            border-radius: 12px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .registry-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.07);
        }

        .registry-stat-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            background: var(--parish-gold-light);
            color: var(--parish-gold-dark);
        }

        .registry-stat-icon.icon-active {
            background: #DCFCE7;
            color: #166534;
        }

        .registry-stat-icon.icon-archived {
            background: #F1F5F9;
            color: #475569;
        }

        .registry-stat-icon.icon-books {
            background: #E0F2FE;
            color: #0369A1;
        }

        .registry-stat-content strong {
            display: block;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--parish-navy);
            line-height: 1.1;
        }

        .registry-stat-content span {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--parish-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        /* ── Formal Search & Filter Card ─────────────────── */
        .registry-control-card {
            background: #FFFFFF;
            border: 1px solid var(--parish-border);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
        }

        .registry-control-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }

        .registry-filter-form {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            flex: 1 1 500px;
        }

        .search-input-wrap {
            position: relative;
            flex: 1 1 320px;
            min-width: 240px;
        }

        .search-input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 0.9rem;
            pointer-events: none;
        }

        .search-input-wrap input {
            width: 100%;
            height: 42px;
            padding: 8px 14px 8px 38px;
            border: 1px solid var(--parish-border);
            border-radius: 8px;
            font-size: 0.9rem;
            color: var(--parish-ink);
            background: #FAF8F5;
            transition: all 0.2s ease;
        }

        .search-input-wrap input:focus {
            background: #FFFFFF;
            border-color: var(--parish-gold);
            outline: none;
            box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
        }

        .status-select-wrap {
            min-width: 150px;
        }

        .status-select-wrap select {
            height: 42px;
            padding: 8px 14px;
            border: 1px solid var(--parish-border);
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--parish-navy);
            background: #FAF8F5;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .status-select-wrap select:focus {
            background: #FFFFFF;
            border-color: var(--parish-gold);
            outline: none;
            box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
        }

        .btn-formal-search {
            height: 42px;
            padding: 0 18px;
            border: 1px solid var(--parish-navy);
            border-radius: 8px;
            background: var(--parish-navy);
            color: #FFFFFF;
            font-size: 0.88rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-formal-search:hover {
            background: var(--parish-navy-light);
            border-color: var(--parish-navy-light);
            color: #FFFFFF;
            box-shadow: 0 2px 6px rgba(21, 34, 56, 0.2);
        }

        .btn-formal-reset {
            height: 42px;
            padding: 0 14px;
            border: 1px solid var(--parish-border);
            border-radius: 8px;
            background: #FFFFFF;
            color: #64748B;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-formal-reset:hover {
            background: #F1F5F9;
            color: #1E293B;
        }

        .btn-formal-add {
            height: 42px;
            padding: 0 20px;
            border: 1px solid #A97F24;
            border-radius: 8px;
            background: linear-gradient(135deg, #D4AF37, #B9863A);
            color: #FFFFFF;
            font-size: 0.88rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(185, 134, 58, 0.28);
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-formal-add:hover {
            background: linear-gradient(135deg, #DEB842, #C89445);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(185, 134, 58, 0.38);
            color: #FFFFFF;
        }

        /* ── Formal Registry Table Card ─────────────────── */
        .registry-table-card {
            background: #FFFFFF;
            border: 1px solid var(--parish-border);
            border-radius: 12px;
            box-shadow: 0 1px 6px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }

        .registry-table-header {
            padding: 16px 22px;
            background: #FFFFFF;
            border-bottom: 1px solid var(--parish-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .registry-table-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .registry-table-title-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--parish-gold-light);
            color: var(--parish-gold-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .registry-table-title h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--parish-navy);
            margin: 0;
        }

        .registry-table-count-badge {
            background: var(--parish-gold-light);
            color: var(--parish-gold-dark);
            border: 1px solid var(--parish-border-gold);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .registry-table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .formal-records-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.86rem;
            min-width: 1100px;
        }

        .formal-records-table thead {
            background: #F8FAFC;
            border-bottom: 2px solid #E2E8F0;
        }

        .formal-records-table th {
            padding: 12px 14px;
            color: #475569;
            font-size: 0.76rem;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            text-align: left;
            white-space: nowrap;
            vertical-align: middle;
            border-bottom: 2px solid #E2E8F0;
        }

        .formal-records-table tbody tr {
            border-bottom: 1px solid #F1F5F9;
            transition: background 0.15s ease;
        }

        .formal-records-table tbody tr:hover {
            background: #FDFBF7;
        }

        .formal-records-table td {
            padding: 14px;
            vertical-align: top;
            color: #334155;
            line-height: 1.45;
        }

        /* ── Table Cell Elements ────────────────────────── */
        .book-page-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #FAF6ED;
            color: #785215;
            border: 1px solid #EADBBA;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 0.78rem;
            font-weight: 750;
            white-space: nowrap;
        }

        .person-baptized-name {
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--parish-navy);
            display: block;
            margin-bottom: 2px;
            letter-spacing: -0.01em;
        }

        .entry-no-pill {
            display: inline-block;
            background: #F1F5F9;
            color: #64748B;
            border-radius: 4px;
            padding: 1px 6px;
            font-size: 0.72rem;
            font-weight: 600;
            margin-top: 3px;
        }

        .date-baptized-badge {
            font-weight: 700;
            color: #1E293B;
            white-space: nowrap;
        }

        .date-baptized-year {
            display: block;
            font-size: 0.78rem;
            color: #64748B;
            font-weight: 600;
        }

        .meta-subtitle {
            display: block;
            font-size: 0.78rem;
            color: #64748B;
            margin-top: 3px;
            line-height: 1.35;
        }

        .meta-subtitle i {
            width: 12px;
            text-align: center;
            color: #94A3B8;
            margin-right: 3px;
        }

        .birth-status-tag {
            display: inline-block;
            background: #F0FDF4;
            color: #166534;
            border: 1px solid #BBF7D0;
            border-radius: 4px;
            padding: 1px 6px;
            font-size: 0.72rem;
            font-weight: 600;
            margin-top: 3px;
            text-transform: capitalize;
        }

        .official-role {
            font-size: 0.75rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }

        .badge-status-active {
            background: var(--parish-success-bg);
            color: var(--parish-success);
            border: 1px solid #A7F3D0;
        }

        .badge-status-archived {
            background: #F1F5F9;
            color: #64748B;
            border: 1px solid #CBD5E1;
        }

        /* ── Action Buttons ─────────────────────────────── */
        .record-actions-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-reg-action {
            height: 32px;
            padding: 0 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .btn-reg-edit {
            background: #EFF6FF;
            color: #1D4ED8;
            border-color: #BFDBFE;
        }

        .btn-reg-edit:hover {
            background: #1D4ED8;
            color: #FFFFFF;
            border-color: #1D4ED8;
        }

        .btn-reg-archive {
            background: #FFF7ED;
            color: #C2410C;
            border-color: #FED7AA;
        }

        .btn-reg-archive:hover {
            background: #C2410C;
            color: #FFFFFF;
            border-color: #C2410C;
        }

        /* ── Pagination ─────────────────────────────────── */
        .registry-pagination-wrap {
            padding: 16px 22px;
            background: #FFFFFF;
            border-top: 1px solid var(--parish-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .pagination-summary-text {
            font-size: 0.85rem;
            color: var(--parish-muted);
            font-weight: 600;
        }

        .pagination-links {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pagination-link {
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--parish-border);
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--parish-navy);
            text-decoration: none;
            background: #FFFFFF;
            transition: all 0.15s ease;
        }

        .pagination-link:hover {
            background: #F8FAFC;
            border-color: var(--parish-gold);
            color: var(--parish-gold-dark);
        }

        .pagination-link.active {
            background: var(--parish-gold);
            border-color: var(--parish-gold);
            color: #FFFFFF;
            font-weight: 750;
        }

        /* ── Standardized Record Modal Overlay & Viewport Containment ── */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1050;
            background-color: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            padding: 20px 16px;
            overflow: hidden;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex !important;
        }

        .modal-content {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid rgba(200, 155, 60, 0.4);
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(0, 0, 0, 0.05);
            width: min(960px, 95vw);
            max-height: 90vh;
            height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin: auto;
            position: relative;
            padding: 0 !important;
        }

        .modal-content.modal-archive-dialog {
            width: min(450px, 92vw) !important;
            height: auto !important;
            max-height: 85vh;
        }

        .record-modal-form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            height: 100%;
            overflow: hidden;
            margin: 0;
        }

        .modal-header {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: linear-gradient(180deg, #FFFFFF, #FAF8F5);
            border-bottom: 1px solid #E2E8F0;
            margin-bottom: 0;
        }

        .modal-header .modal-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-header-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--parish-gold-light);
            color: var(--parish-gold-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }

        .modal-header h4, .modal-header .modal-title-text {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--parish-navy);
            margin: 0;
        }

        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 1.6rem;
            line-height: 1;
            color: #64748B;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .modal-close-btn:hover {
            color: #0F172A;
            background: #E2E8F0;
        }

        .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 24px;
            overscroll-behavior: contain;
            background: #FFFFFF;
        }

        .modal-section-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0 14px;
            padding-bottom: 6px;
            border-bottom: 1px solid #E2E8F0;
        }

        .modal-section-divider:first-child {
            margin-top: 0;
        }

        .modal-section-divider i {
            color: var(--parish-gold-dark);
            font-size: 0.95rem;
        }

        .modal-section-divider h5 {
            font-size: 0.9rem;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--parish-navy);
            margin: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 18px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.84rem;
            font-weight: 700;
            color: #334155;
        }

        .form-group label .required-mark {
            color: #DC2626;
            margin-left: 2px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #CBD5E1;
            border-radius: 7px;
            font-size: 0.9rem;
            color: #0F172A;
            background: #FFFFFF;
            transition: all 0.2s ease;
        }

        .form-group textarea {
            min-height: 74px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--parish-gold);
            box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.18);
        }

        .modal-footer {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            padding: 14px 24px;
            background: #FAF8F5;
            border-top: 1px solid #E2E8F0;
            margin-top: 0;
        }

        .btn-modal-cancel {
            padding: 9px 20px;
            border: 1px solid #CBD5E1;
            border-radius: 7px;
            background: #FFFFFF;
            color: #475569;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-modal-cancel:hover {
            background: #F1F5F9;
            color: #0F172A;
        }

        .btn-modal-save {
            padding: 9px 22px;
            border: 1px solid #A97F24;
            border-radius: 7px;
            background: linear-gradient(135deg, #D4AF37, #B9863A);
            color: #FFFFFF;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(185, 134, 58, 0.25);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-modal-save:hover {
            background: linear-gradient(135deg, #DEB842, #C89445);
            box-shadow: 0 4px 12px rgba(185, 134, 58, 0.35);
            color: #FFFFFF;
        }

        .btn-modal-archive-confirm {
            padding: 9px 22px;
            border: 1px solid #B91C1C;
            border-radius: 7px;
            background: #DC2626;
            color: #FFFFFF;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-modal-archive-confirm:hover {
            background: #B91C1C;
        }

        body.modal-open {
            overflow: hidden !important;
        }

        /* ── Empty State ────────────────────────────────── */
        .registry-empty-state {
            text-align: center;
            padding: 48px 20px;
        }

        .registry-empty-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: var(--parish-gold-light);
            color: var(--parish-gold-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 16px;
        }

        .registry-empty-state h3 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--parish-navy);
            margin-bottom: 6px;
        }

        .registry-empty-state p {
            color: var(--parish-muted);
            font-size: 0.9rem;
            margin-bottom: 18px;
        }

        @media (max-width: 768px) {
            .registry-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .registry-control-flex {
                flex-direction: column;
                align-items: stretch;
            }
            .registry-filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="container-fluid px-0 baptism-registry-shell">
        <!-- Standardized Page Header -->
        <?php
        $page_header_title = 'Baptism Records';
        $page_header_subtitle = 'Official sacramental registry entries, certificates, and archival books.';
        $page_header_icon = 'fa-water';
        $show_back_button = true;
        $back_button_url = 'manage-records.php';
        include '../includes/page_header.php';
        ?>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert_type); ?> alert-dismissible fade show" role="alert" style="border-radius: 10px; border-left: 4px solid;">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Formal Stats Ribbon -->
        <div class="registry-stats-grid">
            <div class="registry-stat-card">
                <div class="registry-stat-icon"><i class="fas fa-water"></i></div>
                <div class="registry-stat-content">
                    <strong><?php echo number_format($stat_total); ?></strong>
                    <span>Total Baptism Records</span>
                </div>
            </div>
            <div class="registry-stat-card">
                <div class="registry-stat-icon icon-active"><i class="fas fa-circle-check"></i></div>
                <div class="registry-stat-content">
                    <strong><?php echo number_format($stat_active); ?></strong>
                    <span>Active Records</span>
                </div>
            </div>
            <div class="registry-stat-card">
                <div class="registry-stat-icon icon-archived"><i class="fas fa-box-archive"></i></div>
                <div class="registry-stat-content">
                    <strong><?php echo number_format($stat_archived); ?></strong>
                    <span>Archived Records</span>
                </div>
            </div>
            <div class="registry-stat-card">
                <div class="registry-stat-icon icon-books"><i class="fas fa-book-bible"></i></div>
                <div class="registry-stat-content">
                    <strong><?php echo number_format($stat_books); ?></strong>
                    <span>Registry Books</span>
                </div>
            </div>
        </div>

        <!-- Formal Search & Control Bar -->
        <div class="registry-control-card">
            <div class="registry-control-flex">
                <div class="registry-filter-form">
                    <div class="search-input-wrap">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search by name, book, page, parents, sponsors, minister..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="status-select-wrap">
                        <select id="statusFilter" onchange="applyFilter()" aria-label="Filter records by status">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Only</option>
                            <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived Only</option>
                        </select>
                    </div>
                    <button type="button" onclick="performSearch()" class="btn-formal-search">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <?php if (!empty($search) || !empty($status_filter)): ?>
                        <a href="baptism-records.php" class="btn-formal-reset" title="Clear all filters">
                            <i class="fas fa-rotate-left"></i> Reset
                        </a>
                    <?php endif; ?>
                </div>
                <div>
                    <button type="button" onclick="openAddModal()" class="btn-formal-add">
                        <i class="fas fa-plus-circle"></i> Add Baptism Record
                    </button>
                </div>
            </div>
        </div>

        <!-- Official Sacramental Records Table Card -->
        <div class="registry-table-card">
            <div class="registry-table-header">
                <div class="registry-table-title">
                    <span class="registry-table-title-icon"><i class="fas fa-book-journal-whills"></i></span>
                    <h2>Baptismal Registry Archive</h2>
                </div>
                <div>
                    <span class="registry-table-count-badge">
                        <i class="fas fa-list-check me-1"></i> <?php echo number_format($total_records); ?> Total Record<?php echo $total_records === 1 ? '' : 's'; ?>
                    </span>
                </div>
            </div>

            <div class="registry-table-responsive">
                <table class="formal-records-table">
                    <thead>
                        <tr>
                            <th style="width: 110px;">Book / Page</th>
                            <th style="width: 120px;">Date Baptized</th>
                            <th style="width: 180px;">Person Baptized</th>
                            <th style="width: 170px;">Birth Details</th>
                            <th style="width: 180px;">Parents</th>
                            <th style="width: 170px;">Sponsors</th>
                            <th style="width: 150px;">Minister</th>
                            <th style="width: 160px;">Parish Officials</th>
                            <th style="width: 120px;">Remarks</th>
                            <th style="width: 90px; text-align: center;">Status</th>
                            <th style="width: 130px; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($records) > 0): ?>
                            <?php foreach ($records as $record): ?>
                                <?php
                                    $record_payload = [
                                        'id' => $record['baptism_id'],
                                        'registry_no' => $record['registry_no'] ?? '',
                                        'book_no' => $record['book_no'] ?? '',
                                        'page_no' => $record['page_no'] ?? '',
                                        'entry_no' => $record['entry_no'] ?? '',
                                        'fullname' => $record['fullname'] ?? '',
                                        'birth_date' => $record['birth_date'] ?? '',
                                        'birth_place' => $record['birth_place'] ?? '',
                                        'birth_status' => $record['birth_status'] ?? '',
                                        'parents' => $record['parents'] ?? '',
                                        'parent_address' => $record['parent_address'] ?? '',
                                        'baptism_date' => $record['baptism_date'] ?? '',
                                        'godparents' => $record['godparents'] ?? '',
                                        'parish_address' => $record['parish_address'] ?? '',
                                        'priest' => $record['priest'] ?? '',
                                        'remarks' => $record['remarks'] ?? '',
                                        'parish_priest' => $record['parish_priest'] ?? '',
                                        'parish_secretary' => $record['parish_secretary'] ?? '',
                                        'status' => $record['status'] ?? 'active',
                                        'request_id' => $record['request_id'] ?? ''
                                    ];
                                    $is_archived = strtolower($record['status'] ?? '') === 'archived';
                                ?>
                                <tr>
                                    <td>
                                        <span class="book-page-badge">
                                            <i class="fas fa-bookmark me-1" style="font-size: 0.68rem;"></i>
                                            Bk. <?php echo htmlspecialchars($record['book_no'] ?: '-'); ?> · Pg. <?php echo htmlspecialchars($record['page_no'] ?: '-'); ?>
                                        </span>
                                        <?php if (!empty($record['entry_no'])): ?>
                                            <span class="entry-no-pill">Entry #<?php echo htmlspecialchars($record['entry_no']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="date-baptized-badge"><?php echo format_baptism_record_date($record['baptism_date'], 'M d, Y'); ?></span>
                                        <?php if (!empty($record['baptism_date'])): ?>
                                            <span class="date-baptized-year"><?php echo date('l', strtotime($record['baptism_date'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="person-baptized-name"><?php echo htmlspecialchars($record['fullname']); ?></strong>
                                        <?php if (!empty($record['registry_no'])): ?>
                                            <span class="meta-subtitle"><i class="fas fa-hashtag"></i> Reg: <?php echo htmlspecialchars($record['registry_no']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><i class="fas fa-cake-candles me-1 text-muted"></i> <strong><?php echo format_baptism_record_date($record['birth_date'] ?? ''); ?></strong></div>
                                        <?php if (!empty($record['birth_place'])): ?>
                                            <span class="meta-subtitle"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($record['birth_place']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($record['birth_status'])): ?>
                                            <span class="birth-status-tag"><?php echo htmlspecialchars($record['birth_status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #1E293B;"><?php echo htmlspecialchars($record['parents'] ?? 'N/A'); ?></div>
                                        <?php if (!empty($record['parent_address'])): ?>
                                            <span class="meta-subtitle"><i class="fas fa-house"></i> <?php echo htmlspecialchars($record['parent_address']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #1E293B;"><?php echo htmlspecialchars($record['godparents'] ?? 'N/A'); ?></div>
                                        <?php if (!empty($record['parish_address'])): ?>
                                            <span class="meta-subtitle"><i class="fas fa-church"></i> <?php echo htmlspecialchars($record['parish_address']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #1E293B;"><i class="fas fa-cross me-1 text-muted" style="font-size: 0.75rem;"></i> <?php echo htmlspecialchars($record['priest'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td>
                                        <div><span class="official-role">Pastor:</span> <?php echo htmlspecialchars($record['parish_priest'] ?: 'N/A'); ?></div>
                                        <span class="meta-subtitle"><span class="official-role">Secretary:</span> <?php echo htmlspecialchars($record['parish_secretary'] ?: 'N/A'); ?></span>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.8rem; color: #64748B;"><?php echo htmlspecialchars($record['remarks'] ?: '-'); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge-status <?php echo $is_archived ? 'badge-status-archived' : 'badge-status-active'; ?>">
                                            <i class="fas <?php echo $is_archived ? 'fa-box-archive' : 'fa-circle-check'; ?>"></i>
                                            <?php echo $is_archived ? 'Archived' : 'Active'; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <div class="record-actions-wrap justify-content-center">
                                            <button type="button" class="btn-reg-action btn-reg-edit" onclick="openEditModal(<?php echo js_value($record_payload); ?>)" title="Edit this record">
                                                <i class="fas fa-pen-to-square"></i> Edit
                                            </button>
                                            <button type="button" class="btn-reg-action btn-reg-archive" onclick="confirmArchive(<?php echo (int)$record['baptism_id']; ?>)" title="Archive this record">
                                                <i class="fas fa-box-archive"></i> Archive
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11">
                                    <div class="registry-empty-state">
                                        <div class="registry-empty-icon"><i class="fas fa-book-open"></i></div>
                                        <h3>No Baptism Records Found</h3>
                                        <p>There are no baptismal registry entries matching your filter criteria.</p>
                                        <button type="button" onclick="openAddModal()" class="btn-formal-add">
                                            <i class="fas fa-plus-circle"></i> Add New Record
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Formal Pagination -->
            <?php if ($total_pages > 1 || $total_records > 0): ?>
                <div class="registry-pagination-wrap">
                    <div class="pagination-summary-text">
                        Showing <?php echo $total_records > 0 ? ($offset + 1) : 0; ?> to <?php echo min($offset + $per_page, $total_records); ?> of <?php echo number_format($total_records); ?> registry entries
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-links">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="pagination-link" title="Previous Page">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            if ($start_page > 1) {
                                echo '<a href="?page=1&search=' . urlencode($search) . '&status=' . urlencode($status_filter) . '" class="pagination-link">1</a>';
                                if ($start_page > 2) echo '<span class="px-1 text-muted">...</span>';
                            }
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor;
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) echo '<span class="px-1 text-muted">...</span>';
                                echo '<a href="?page=' . $total_pages . '&search=' . urlencode($search) . '&status=' . urlencode($status_filter) . '" class="pagination-link">' . $total_pages . '</a>';
                            }
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="pagination-link" title="Next Page">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formal Add/Edit Record Modal -->
    <div id="recordModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-content">
            <form id="recordForm" method="POST" action="" class="record-modal-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" id="actionInput" name="action" value="add">
                <input type="hidden" id="recordIdInput" name="record_id" value="">

                <div class="modal-header">
                    <div class="modal-title-wrap">
                        <span class="modal-header-icon"><i class="fas fa-water"></i></span>
                        <h4 id="modalTitle" class="modal-title-text">Add Baptism Record</h4>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal()" aria-label="Close dialog">&times;</button>
                </div>

                <div class="modal-body">
                    <!-- Section 1: Book & Registry Coordinates -->
                    <div class="modal-section-divider">
                        <i class="fas fa-book-bookmark"></i>
                        <h5>1. Registry Book Coordinates</h5>
                    </div>
                    <div class="form-grid">
                        <input type="hidden" id="registryNo" name="registry_no">
                        <div class="form-group">
                            <label for="registryNoVisible">Registry Number</label>
                            <input type="text" id="registryNoVisible" name="registry_no_display" oninput="document.getElementById('registryNo').value=this.value" placeholder="e.g. REG-2025-001 or use Bk+Pg+Entry">
                        </div>
                        <div class="form-group">
                            <label for="bookNo">Book Number</label>
                            <input type="text" id="bookNo" name="book_no" placeholder="Book No. (e.g. 1)">
                        </div>
                        <div class="form-group">
                            <label for="pageNo">Page Number</label>
                            <input type="text" id="pageNo" name="page_no" placeholder="Page No. (e.g. 24)">
                        </div>
                        <div class="form-group">
                            <label for="entryNo">Entry Number</label>
                            <input type="text" id="entryNo" name="entry_no" placeholder="Entry No. (e.g. 102)">
                        </div>
                    </div>

                    <!-- Section 2: Person Baptized & Sacramental Dates -->
                    <div class="modal-section-divider">
                        <i class="fas fa-user-check"></i>
                        <h5>2. Person Baptized & Sacramental Dates</h5>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="fullName">Person Baptized (Full Name) <span class="required-mark">*</span></label>
                            <input type="text" id="fullName" name="fullname" required placeholder="First Name, Middle Name, Last Name, Suffix">
                        </div>
                        <div class="form-group">
                            <label for="baptismDate">Date of Baptism <span class="required-mark">*</span></label>
                            <input type="date" id="baptismDate" name="baptism_date" required>
                        </div>
                        <div class="form-group">
                            <label for="birthDate">Date of Birth <span class="required-mark">*</span></label>
                            <input type="date" id="birthDate" name="birth_date" required max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="birthPlace">Place of Birth <span class="required-mark">*</span></label>
                            <input type="text" id="birthPlace" name="birth_place" required placeholder="City / Municipality, Province">
                        </div>
                        <div class="form-group">
                            <label for="birthStatus">Status of Birth</label>
                            <input type="text" id="birthStatus" name="birth_status" placeholder="Legitimate, Illegitimate, etc.">
                        </div>
                    </div>

                    <!-- Section 3: Parents & Residence -->
                    <div class="modal-section-divider">
                        <i class="fas fa-people-roof"></i>
                        <h5>3. Parents & Residence</h5>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="parentsName">Parents (Father & Mother Full Names) <span class="required-mark">*</span></label>
                            <input type="text" id="parentsName" name="parents" required placeholder="Father's Full Name & Mother's Maiden Name">
                        </div>
                        <div class="form-group full-width">
                            <label for="parentAddress">Parents Residence / Address</label>
                            <input type="text" id="parentAddress" name="parent_address" placeholder="Barangay, Municipality / City, Province">
                        </div>
                    </div>

                    <!-- Section 4: Sponsors & Parish Details -->
                    <div class="modal-section-divider">
                        <i class="fas fa-hands-holding-child"></i>
                        <h5>4. Sponsors & Parish Details</h5>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="godparents">Sponsors / Godparents (Full Names) <span class="required-mark">*</span></label>
                            <input type="text" id="godparents" name="godparents" required placeholder="Names of Godfathers & Godmothers (separated by commas)">
                        </div>
                        <div class="form-group full-width">
                            <label for="parishAddress">Parish / Chapel Address</label>
                            <input type="text" id="parishAddress" name="parish_address" placeholder="San Lorenzo Ruiz Mission Station or local chapel">
                        </div>
                    </div>

                    <!-- Section 5: Ministers & Administration -->
                    <div class="modal-section-divider">
                        <i class="fas fa-church"></i>
                        <h5>5. Ministers & Administration</h5>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="priestName">Officiating Minister / Priest <span class="required-mark">*</span></label>
                            <input type="text" id="priestName" name="priest" required placeholder="Rev. Fr. Name">
                        </div>
                        <div class="form-group">
                            <label for="parishPriest">Parish Priest</label>
                            <input type="text" id="parishPriest" name="parish_priest" placeholder="Name printed above Parish Priest">
                        </div>
                        <div class="form-group">
                            <label for="parishSecretary">Parish Secretary</label>
                            <input type="text" id="parishSecretary" name="parish_secretary" placeholder="Name printed above Parish Secretary">
                        </div>
                    </div>

                    <!-- Section 6: Annotations & Status -->
                    <div class="modal-section-divider">
                        <i class="fas fa-file-pen"></i>
                        <h5>6. Annotations & System Status</h5>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="remarks">Remarks / Marginal Annotations</label>
                            <textarea id="remarks" name="remarks" placeholder="Optional notes, marriage annotation, confirmation note, etc."></textarea>
                        </div>
                        <div class="form-group">
                            <label for="recordStatus">Record Status</label>
                            <select id="recordStatus" name="status">
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="requestId">Link to Online Request</label>
                            <select id="requestId" name="request_id">
                                <option value="">-- No Request Linked --</option>
                                <?php foreach ($requests_list as $req): ?>
                                    <option value="<?php echo (int)$req['request_id']; ?>">
                                        <?php echo htmlspecialchars($req['reference_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full-width" id="correctionReasonGroup" style="display: none;">
                            <label for="correctionReason">Correction Reason <span class="required-mark">* (required when updating official record)</span></label>
                            <textarea id="correctionReason" name="correction_reason" minlength="5" placeholder="Specify what data is being corrected and rationale..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-modal-save">
                        <i class="fas fa-floppy-disk"></i> Save Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Formal Archive Confirmation Modal -->
    <div id="deleteModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="archiveTitle">
        <div class="modal-content modal-archive-dialog">
            <form id="deleteForm" method="POST" action="" class="record-modal-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" id="deleteRecordId" name="record_id" value="">
                <div class="modal-header">
                    <div class="modal-title-wrap">
                        <span class="modal-header-icon" style="background: #FEE2E2; color: #DC2626;"><i class="fas fa-box-archive"></i></span>
                        <h4 id="archiveTitle" class="modal-title-text">Confirm Archive</h4>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeDeleteModal()" aria-label="Close dialog">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 16px; color: #475569; font-size: 0.92rem; line-height: 1.5;">
                        Archive this baptism record? It will be safely archived and hidden from the active registry list.
                    </p>
                    <div class="form-group mb-0">
                        <label for="archiveReason" style="font-weight: 700; color: #334155; font-size: 0.85rem; margin-bottom: 6px; display: block;">Archive Reason <span style="color: #DC2626;">*</span></label>
                        <textarea id="archiveReason" name="archive_reason" required minlength="5" placeholder="State the reason for archiving this baptismal record..." style="width: 100%; padding: 10px; border: 1px solid #CBD5E1; border-radius: 7px; min-height: 80px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-modal-archive-confirm">
                        <i class="fas fa-box-archive"></i> Archive Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/components.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Open Add Modal Function
        function openAddModal() {
            document.getElementById('recordForm').reset();
            document.getElementById('actionInput').value = 'add';
            document.getElementById('recordIdInput').value = '';
            document.getElementById('registryNo').value = '';
            document.getElementById('registryNoVisible').value = '';
            document.getElementById('entryNo').value = '';
            document.getElementById('bookNo').value = '';
            document.getElementById('pageNo').value = '';
            document.getElementById('modalTitle').textContent = 'Add Baptism Record';
            const reasonGroup = document.getElementById('correctionReasonGroup');
            if (reasonGroup) {
                reasonGroup.style.display = 'none';
                const reasonInput = document.getElementById('correctionReason');
                if (reasonInput) reasonInput.removeAttribute('required');
            }
            document.getElementById('recordModal').classList.add('show');
            document.body.classList.add('modal-open');
        }

        // Open Edit Modal Function
        function openEditModal(record) {
            document.getElementById('recordIdInput').value = record.id || '';
            document.getElementById('registryNo').value = record.registry_no || '';
            document.getElementById('registryNoVisible').value = record.registry_no || '';
            document.getElementById('entryNo').value = record.entry_no || '';
            document.getElementById('bookNo').value = record.book_no || '';
            document.getElementById('pageNo').value = record.page_no || '';
            document.getElementById('fullName').value = record.fullname || '';
            document.getElementById('birthDate').value = record.birth_date || '';
            document.getElementById('birthPlace').value = record.birth_place || '';
            document.getElementById('birthStatus').value = record.birth_status || '';
            document.getElementById('parentsName').value = record.parents || '';
            document.getElementById('parentAddress').value = record.parent_address || '';
            document.getElementById('baptismDate').value = record.baptism_date || '';
            document.getElementById('godparents').value = record.godparents || '';
            document.getElementById('parishAddress').value = record.parish_address || '';
            document.getElementById('priestName').value = record.priest || '';
            document.getElementById('remarks').value = record.remarks || '';
            document.getElementById('parishPriest').value = record.parish_priest || '';
            document.getElementById('parishSecretary').value = record.parish_secretary || '';
            document.getElementById('recordStatus').value = record.status || 'active';
            document.getElementById('requestId').value = record.request_id || '';
            document.getElementById('actionInput').value = 'edit';
            document.getElementById('modalTitle').textContent = 'Edit Baptism Record';
            const reasonGroup = document.getElementById('correctionReasonGroup');
            if (reasonGroup) {
                reasonGroup.style.display = 'block';
                const reasonInput = document.getElementById('correctionReason');
                if (reasonInput) reasonInput.setAttribute('required', 'required');
            }
            document.getElementById('recordModal').classList.add('show');
            document.body.classList.add('modal-open');
        }

        // Close Modal Function
        function closeModal() {
            document.getElementById('recordModal').classList.remove('show');
            document.body.classList.remove('modal-open');
        }

        // Confirm Archive Function
        function confirmArchive(id) {
            document.getElementById('deleteRecordId').value = id;
            document.getElementById('deleteModal').classList.add('show');
            document.body.classList.add('modal-open');
        }

        // Close Delete Modal Function
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            document.body.classList.remove('modal-open');
        }

        // Perform Search Function
        function performSearch() {
            const search = document.getElementById('searchInput').value.trim();
            const status = document.getElementById('statusFilter').value;
            window.location.href = `?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&page=1`;
        }

        // Apply Filter Function
        function applyFilter() {
            performSearch();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('recordModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        };

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });

        // Allow Enter key in search to perform search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
    </script>
</div>
<?php include '../templates/footer.php'; ?>
