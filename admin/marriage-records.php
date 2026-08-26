<?php
/**
 * MARRIAGE RECORDS MANAGEMENT
 * Admin page for managing marriage sacramental records
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

// Check admin access
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

// Marriage Column Exists Function - Documents this helper's role in the parish management workflow.
function marriage_column_exists($conn, $column) {
    return schemaColumnExists($conn, 'marriage_records', (string) $column);
}

// Ensure Marriage Record Book Schema Function - Documents this helper's role in the parish management workflow.
function ensure_marriage_record_book_schema($conn) {
    return requireSchemaColumns($conn, 'marriage_records', [
        'request_id', 'registry_no', 'husband_status', 'husband_age',
        'husband_birth_origin', 'husband_residence', 'husband_parents',
        'wife_status', 'wife_age', 'wife_birth_origin', 'wife_residence',
        'wife_parents', 'witnesses_residence', 'remarks', 'parish_priest',
        'parish_secretary'
    ], 'marriage records');
}

// Format Marriage Record Date Function - Documents this helper's role in the parish management workflow.
function format_marriage_record_date($date_value, $format = 'M d, Y') {
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

ensure_marriage_record_book_schema($conn);

// Handle POST actions
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$message = '';
$alert_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add','edit','archive','restore'], true)) {
    requireValidCsrfToken();
    try {
        $records=new SacramentalRecordService($conn);$actor=(int)($_SESSION['user_id']??0);
        if($action==='add'){$records->create('marriage',$_POST,$actor);$notice='Marriage record created.';}
        elseif($action==='edit'){$records->requestCorrection('marriage',(int)($_POST['record_id']??0),$_POST,(string)($_POST['correction_reason']??''),$actor);$notice='Correction submitted for review; the official record was not overwritten.';}
        elseif($action==='archive'){$records->archive('marriage',(int)($_POST['record_id']??0),(string)($_POST['archive_reason']??''),$actor);$notice='Marriage record archived.';}
        else{$records->restore('marriage',(int)($_POST['record_id']??0),$actor);$notice='Marriage record restored.';}
        redirectWithNotification('marriage-records.php',$notice,'success');
    } catch(Throwable $exception){$message=$exception->getMessage();$alert_type='danger';$action=null;}
}

// Add new marriage record
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $registry_no = trim($_POST['registry_no'] ?? '');
    $husband_name = trim($_POST['husband_name'] ?? '');
    $husband_status = trim($_POST['husband_status'] ?? '');
    $husband_age = trim($_POST['husband_age'] ?? '');
    $husband_birth_origin = trim($_POST['husband_birth_origin'] ?? '');
    $husband_residence = trim($_POST['husband_residence'] ?? '');
    $husband_parents = trim($_POST['husband_parents'] ?? '');
    $wife_name = trim($_POST['wife_name'] ?? '');
    $wife_status = trim($_POST['wife_status'] ?? '');
    $wife_age = trim($_POST['wife_age'] ?? '');
    $wife_birth_origin = trim($_POST['wife_birth_origin'] ?? '');
    $wife_residence = trim($_POST['wife_residence'] ?? '');
    $wife_parents = trim($_POST['wife_parents'] ?? '');
    $wedding_date = !empty($_POST['wedding_date']) ? $_POST['wedding_date'] : null;
    $sponsors = trim($_POST['sponsors'] ?? '');
    $witnesses_residence = trim($_POST['witnesses_residence'] ?? '');
    $officiating_priest = trim($_POST['officiating_priest'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $parish_priest = trim($_POST['parish_priest'] ?? '');
    $parish_secretary = trim($_POST['parish_secretary'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $request_id = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;

    if ($husband_name && $wife_name && $wedding_date) {
        $stmt = $conn->prepare("INSERT INTO marriage_records (registry_no, husband_name, husband_status, husband_age, husband_birth_origin, husband_residence, husband_parents, wife_name, wife_status, wife_age, wife_birth_origin, wife_residence, wife_parents, wedding_date, sponsors, witnesses_residence, officiating_priest, remarks, parish_priest, parish_secretary, status, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssssssssssssssssssi", $registry_no, $husband_name, $husband_status, $husband_age, $husband_birth_origin, $husband_residence, $husband_parents, $wife_name, $wife_status, $wife_age, $wife_birth_origin, $wife_residence, $wife_parents, $wedding_date, $sponsors, $witnesses_residence, $officiating_priest, $remarks, $parish_priest, $parish_secretary, $status, $request_id);
            if ($stmt->execute()) {
                $message = "Marriage record added successfully!";
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

// Update marriage record
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    $registry_no = trim($_POST['registry_no'] ?? '');
    $husband_name = trim($_POST['husband_name'] ?? '');
    $husband_status = trim($_POST['husband_status'] ?? '');
    $husband_age = trim($_POST['husband_age'] ?? '');
    $husband_birth_origin = trim($_POST['husband_birth_origin'] ?? '');
    $husband_residence = trim($_POST['husband_residence'] ?? '');
    $husband_parents = trim($_POST['husband_parents'] ?? '');
    $wife_name = trim($_POST['wife_name'] ?? '');
    $wife_status = trim($_POST['wife_status'] ?? '');
    $wife_age = trim($_POST['wife_age'] ?? '');
    $wife_birth_origin = trim($_POST['wife_birth_origin'] ?? '');
    $wife_residence = trim($_POST['wife_residence'] ?? '');
    $wife_parents = trim($_POST['wife_parents'] ?? '');
    $wedding_date = !empty($_POST['wedding_date']) ? $_POST['wedding_date'] : null;
    $sponsors = trim($_POST['sponsors'] ?? '');
    $witnesses_residence = trim($_POST['witnesses_residence'] ?? '');
    $officiating_priest = trim($_POST['officiating_priest'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $parish_priest = trim($_POST['parish_priest'] ?? '');
    $parish_secretary = trim($_POST['parish_secretary'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $request_id = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;

    if ($record_id && $husband_name && $wife_name && $wedding_date) {
        $stmt = $conn->prepare("UPDATE marriage_records SET registry_no=?, husband_name=?, husband_status=?, husband_age=?, husband_birth_origin=?, husband_residence=?, husband_parents=?, wife_name=?, wife_status=?, wife_age=?, wife_birth_origin=?, wife_residence=?, wife_parents=?, wedding_date=?, sponsors=?, witnesses_residence=?, officiating_priest=?, remarks=?, parish_priest=?, parish_secretary=?, status=?, request_id=? WHERE marriage_id=?");
        if ($stmt) {
            $stmt->bind_param("sssssssssssssssssssssii", $registry_no, $husband_name, $husband_status, $husband_age, $husband_birth_origin, $husband_residence, $husband_parents, $wife_name, $wife_status, $wife_age, $wife_birth_origin, $wife_residence, $wife_parents, $wedding_date, $sponsors, $witnesses_residence, $officiating_priest, $remarks, $parish_priest, $parish_secretary, $status, $request_id, $record_id);
            if ($stmt->execute()) {
                $message = "Marriage record updated successfully!";
                $alert_type = "success";
            } else {
                $message = "Error updating record: " . $stmt->error;
                $alert_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Archive marriage record
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    if ($record_id) {
        $stmt = $conn->prepare("UPDATE marriage_records SET status='archived', updated_at=NOW() WHERE marriage_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $record_id);
            if ($stmt->execute()) {
                $message = "Marriage record archived successfully!";
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
    $where_clauses[] = "(registry_no LIKE ? OR husband_name LIKE ? OR wife_name LIKE ? OR husband_parents LIKE ? OR wife_parents LIKE ? OR sponsors LIKE ? OR witnesses_residence LIKE ? OR officiating_priest LIKE ? OR husband_birth_origin LIKE ? OR wife_birth_origin LIKE ? OR husband_residence LIKE ? OR wife_residence LIKE ?)";
    $search_param = "%$search%";
    for ($i = 0; $i < 12; $i++) {
        $params[] = $search_param;
    }
    $param_types .= "ssssssssssss";
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

$where = implode(" AND ", $where_clauses);

// Get total count
$count_query = "SELECT COUNT(*) as count FROM marriage_records WHERE $where";
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

$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// Get records
$query = "SELECT * FROM marriage_records WHERE $where ORDER BY wedding_date DESC LIMIT ? OFFSET ?";
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

$page_title = 'Marriage Records - Parish Management';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <style>
        :root {
            --primary-navy: #1a1f3a;
            --primary-royal-blue: #004085;
            --primary-gold: #d4af37;
            --status-success: #28a745;
            --status-warning: #ffc107;
            --status-danger: #dc3545;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8eef5 100%);
            font-family: 'Inter', sans-serif;
        }

        .admin-content {
            margin-left: 280px;
            padding: 20px 24px;
            transition: margin-left 0.3s;
            max-width: 100%;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-navy);
            margin-bottom: 10px;
        }

        .card-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 18px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary-gold);
        }

        .section-title {
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--primary-navy);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-gold);
        }

        .btn-primary-gold {
            background: var(--primary-gold);
            color: var(--primary-navy);
            border: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary-gold:hover {
            background: #e8c547;
            color: var(--primary-navy);
        }

        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-bar input, .search-bar select {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 0.95rem;
        }

        .records-table {
            width: 100%;
            border-collapse: collapse;
        }

        .records-table thead {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }

        .records-table th {
            padding: 12px;
            font-weight: 600;
            color: var(--primary-navy);
            text-align: left;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .records-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            color: #6c757d;
            vertical-align: top;
        }

        .records-table .text-strong {
            color: var(--primary-navy);
            font-weight: 700;
        }

        .record-muted {
            display: block;
            color: #8792a2;
            font-size: 0.82rem;
            margin-top: 4px;
        }

        .records-table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-active {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-archived {
            background: #f5f5f5;
            color: #9e9e9e;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-edit {
            background: #e3f2fd;
            color: #1976d2;
        }

        .btn-edit:hover {
            background: #1976d2;
            color: white;
        }

        .btn-delete {
            background: #fff7d5;
            color: #80611b;
        }

        .btn-delete:hover {
            background: #d7ad43;
            color: #181204;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal.show {
            display: block;
        }

        .modal-content {
            background-color: white;
            margin: 3% auto;
            padding: 30px;
            border: 1px solid #ccc;
            border-radius: 12px;
            width: 90%;
            max-width: 980px;
            max-height: 88vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-navy);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--primary-navy);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px 18px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .form-group textarea {
            min-height: 84px;
            resize: vertical;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .modal-footer button {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-save {
            background: var(--primary-royal-blue);
            color: white;
        }

        .btn-save:hover {
            background: var(--primary-navy);
        }

        .btn-cancel {
            background: #e0e0e0;
            color: #666;
        }

        .btn-cancel:hover {
            background: #d0d0d0;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-color: #4caf50;
        }

        .alert-danger {
            background: #ffebee;
            color: #c62828;
            border-color: #f44336;
        }

        .alert-warning {
            background: #fff3e0;
            color: #e65100;
            border-color: #ff9800;
        }

        @media (max-width: 768px) {
            .admin-content {
                margin-left: 70px;
                padding: 20px 15px;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .search-bar {
                flex-direction: column;
            }

            .records-table {
                font-size: 0.85rem;
            }

            .records-table th,
            .records-table td {
                padding: 10px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-section {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/premium-parish.css') ? filemtime(__DIR__ . '/../assets/css/premium-parish.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/parish-design-system.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/parish-design-system.css') ? filemtime(__DIR__ . '/../assets/css/parish-design-system.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/admin-sidebar.css') ? filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body class="premium-admin">
    <div class="premium-admin-shell">
        <!-- Include Admin Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>

        <!-- Main Content -->
        <div class="premium-admin-content pds-page-container">
            <!-- Standardized Page Header -->
            <?php
            $page_header_title = 'Marriage Records';
            $page_header_subtitle = 'Manage and digitize Holy Matrimony sacramental registry entries.';
            $page_header_icon = 'fa-ring';
            $show_back_button = true;
            $back_button_url = 'manage-records.php';
            include '../includes/page_header.php';
            ?>

            <!-- Alert Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo htmlspecialchars($alert_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Search & Filter -->
            <div class="card-section">
                <div class="search-bar">
                    <input type="text" id="searchInput" placeholder="Search by record no., names, parents, witnesses, residence, or minister..." value="<?php echo htmlspecialchars($search); ?>">
                    <select id="statusFilter" onchange="applyFilter()">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    </select>
                    <button onclick="performSearch()" class="btn btn-primary-gold">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button onclick="openAddModal()" class="btn btn-primary-gold">
                        <i class="fas fa-plus"></i> Add Record
                    </button>
                </div>
            </div>

            <!-- Records Table -->
            <div class="card-section">
                <div class="section-title">
                    <i class="fas fa-table"></i> Marriage Records (<?php echo $total_records; ?> total)
                </div>
                <div style="overflow-x: auto;">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Year</th>
                                <th>Marriage Contract</th>
                                <th>Contracting Parties</th>
                                <th>Status / Age</th>
                                <th>Origin of Birth</th>
                                <th>Residence</th>
                                <th>Parents</th>
                                <th>Witnesses</th>
                                <th>Minister</th>
                                <th>Remarks</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) > 0): ?>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                        $record_payload = [
                                            'id' => $record['marriage_id'],
                                            'registry_no' => $record['registry_no'] ?? '',
                                            'husband_name' => $record['husband_name'] ?? '',
                                            'husband_status' => $record['husband_status'] ?? '',
                                            'husband_age' => $record['husband_age'] ?? '',
                                            'husband_birth_origin' => $record['husband_birth_origin'] ?? '',
                                            'husband_residence' => $record['husband_residence'] ?? '',
                                            'husband_parents' => $record['husband_parents'] ?? '',
                                            'wife_name' => $record['wife_name'] ?? '',
                                            'wife_status' => $record['wife_status'] ?? '',
                                            'wife_age' => $record['wife_age'] ?? '',
                                            'wife_birth_origin' => $record['wife_birth_origin'] ?? '',
                                            'wife_residence' => $record['wife_residence'] ?? '',
                                            'wife_parents' => $record['wife_parents'] ?? '',
                                            'wedding_date' => $record['wedding_date'] ?? '',
                                            'sponsors' => $record['sponsors'] ?? '',
                                            'witnesses_residence' => $record['witnesses_residence'] ?? '',
                                            'officiating_priest' => $record['officiating_priest'] ?? '',
                                            'remarks' => $record['remarks'] ?? '',
                                            'parish_priest' => $record['parish_priest'] ?? '',
                                            'parish_secretary' => $record['parish_secretary'] ?? '',
                                            'status' => $record['status'] ?? 'active',
                                            'request_id' => $record['request_id'] ?? '',
                                            'book_no' => $record['book_no'] ?? '', 'page_no' => $record['page_no'] ?? '', 'entry_no' => $record['entry_no'] ?? '',
                                            'husband_birth_date' => $record['husband_birth_date'] ?? '', 'wife_birth_date' => $record['wife_birth_date'] ?? '', 'wedding_location' => $record['wedding_location'] ?? ''
                                        ];
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($record['registry_no'] ?: $record['marriage_id']); ?></td>
                                        <td><?php echo !empty($record['wedding_date']) ? date('Y', strtotime($record['wedding_date'])) : 'N/A'; ?></td>
                                        <td><?php echo format_marriage_record_date($record['wedding_date'], 'F d'); ?></td>
                                        <td>
                                            <span class="text-strong"><?php echo htmlspecialchars($record['husband_name']); ?></span>
                                            <span class="record-muted"><?php echo htmlspecialchars($record['wife_name']); ?></span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['husband_status'] ?? ''); ?>
                                            <?php if (!empty($record['husband_age'])): ?><span class="record-muted">Age: <?php echo htmlspecialchars($record['husband_age']); ?></span><?php endif; ?>
                                            <?php if (!empty($record['wife_status']) || !empty($record['wife_age'])): ?>
                                                <span class="record-muted"><?php echo htmlspecialchars(trim(($record['wife_status'] ?? '') . (!empty($record['wife_age']) ? ' / Age: ' . $record['wife_age'] : ''))); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['husband_birth_origin'] ?? 'N/A'); ?>
                                            <?php if (!empty($record['wife_birth_origin'])): ?><span class="record-muted"><?php echo htmlspecialchars($record['wife_birth_origin']); ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['husband_residence'] ?? 'N/A'); ?>
                                            <?php if (!empty($record['wife_residence'])): ?><span class="record-muted"><?php echo htmlspecialchars($record['wife_residence']); ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['husband_parents'] ?? 'N/A'); ?>
                                            <?php if (!empty($record['wife_parents'])): ?><span class="record-muted"><?php echo htmlspecialchars($record['wife_parents']); ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['sponsors'] ?? 'N/A'); ?>
                                            <?php if (!empty($record['witnesses_residence'])): ?><span class="record-muted"><?php echo htmlspecialchars($record['witnesses_residence']); ?></span><?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['officiating_priest'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['remarks'] ?? ''); ?></td>
                                        <td>
                                            <span class="status-badge badge-<?php echo strtolower($record['status']); ?>">
                                                <?php echo ucfirst($record['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-edit" onclick="openEditModal(<?php echo js_value($record_payload); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="action-btn btn-delete" onclick="confirmArchive(<?php echo $record['marriage_id']; ?>)">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" style="text-align: center; padding: 30px; color: #6c757d;">
                                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                                        No marriage records found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div style="margin-top: 20px; text-align: center;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               style="padding: 8px 12px; margin: 0 3px; border-radius: 6px; text-decoration: none; background: <?php echo $i === $page ? 'var(--primary-gold)' : '#e0e0e0'; ?>; color: <?php echo $i === $page ? 'var(--primary-navy)' : '#666'; ?>; font-weight: 600;">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="recordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modalTitle">Add Marriage Record</span>
                <span onclick="closeModal()" style="cursor: pointer; font-size: 1.5rem; color: #999;">&times;</span>
            </div>
            <form id="recordForm" method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" id="actionInput" name="action" value="add">
                <input type="hidden" id="recordIdInput" name="record_id" value="">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Record No.</label>
                        <input type="text" id="registryNo" name="registry_no">
                    </div>
                    <div class="form-group"><label>Book / Page / Entry</label><div style="display:flex;gap:6px"><input id="bookNo" name="book_no" placeholder="Book"><input id="pageNo" name="page_no" placeholder="Page"><input id="entryNo" name="entry_no" placeholder="Entry"></div></div>

                    <div class="form-group">
                        <label>Marriage Contract Date *</label>
                        <input type="date" id="weddingDate" name="wedding_date" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Contracting Party Name and Family Name *</label>
                        <input type="text" id="husbandName" name="husband_name" placeholder="Husband / Groom" required>
                    </div>
                    <div class="form-group"><label>Groom birth date *</label><input id="husbandBirthDate" type="date" name="husband_birth_date" required max="<?php echo date('Y-m-d'); ?>"></div>

                    <div class="form-group">
                        <label>Status</label>
                        <input type="text" id="husbandStatus" name="husband_status" placeholder="Single, widower, etc.">
                    </div>

                    <div class="form-group">
                        <label>Age</label>
                        <input type="text" id="husbandAge" name="husband_age">
                    </div>

                    <div class="form-group">
                        <label>Origin of Birth</label>
                        <input type="text" id="husbandBirthOrigin" name="husband_birth_origin">
                    </div>

                    <div class="form-group">
                        <label>Residence</label>
                        <input type="text" id="husbandResidence" name="husband_residence">
                    </div>

                    <div class="form-group full-width">
                        <label>Parents Name and Family Name</label>
                        <input type="text" id="husbandParents" name="husband_parents">
                    </div>

                    <div class="form-group full-width">
                        <label>Contracting Party Name and Family Name *</label>
                        <input type="text" id="wifeName" name="wife_name" placeholder="Wife / Bride" required>
                    </div>
                    <div class="form-group"><label>Bride birth date *</label><input id="wifeBirthDate" type="date" name="wife_birth_date" required max="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="form-group"><label>Wedding location *</label><input id="weddingLocation" type="text" name="wedding_location" required></div>

                    <div class="form-group">
                        <label>Status</label>
                        <input type="text" id="wifeStatus" name="wife_status" placeholder="Single, widow, etc.">
                    </div>

                    <div class="form-group">
                        <label>Age</label>
                        <input type="text" id="wifeAge" name="wife_age">
                    </div>

                    <div class="form-group">
                        <label>Origin of Birth</label>
                        <input type="text" id="wifeBirthOrigin" name="wife_birth_origin">
                    </div>

                    <div class="form-group">
                        <label>Residence</label>
                        <input type="text" id="wifeResidence" name="wife_residence">
                    </div>

                    <div class="form-group full-width">
                        <label>Parents Name and Family Name</label>
                        <input type="text" id="wifeParents" name="wife_parents">
                    </div>

                    <div class="form-group full-width">
                        <label>Witnesses Name and Family Name *</label>
                        <input type="text" id="sponsors" name="sponsors" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Residence</label>
                        <input type="text" id="witnessesResidence" name="witnesses_residence">
                    </div>

                    <div class="form-group full-width">
                        <label>Name of Minister *</label>
                        <input type="text" id="officiatingPriest" name="officiating_priest" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Remarks</label>
                        <textarea id="remarks" name="remarks"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Parish Priest</label>
                        <input type="text" id="parishPriest" name="parish_priest" placeholder="Name printed above Parish Priest">
                    </div>

                    <div class="form-group">
                        <label>Parish Secretary</label>
                        <input type="text" id="parishSecretary" name="parish_secretary" placeholder="Name printed above Parish Secretary">
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select id="recordStatus" name="status">
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Link to Request</label>
                        <select id="requestId" name="request_id">
                            <option value="">-- No Request --</option>
                            <?php foreach ($requests_list as $req): ?>
                                <option value="<?php echo $req['request_id']; ?>">
                                    <?php echo htmlspecialchars($req['reference_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full-width"><label>Correction reason (required when editing)</label><textarea name="correction_reason" minlength="5"></textarea></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Archive Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <span>Confirm Archive</span>
                <span onclick="closeDeleteModal()" style="cursor: pointer; font-size: 1.5rem; color: #999;">&times;</span>
            </div>
            <p style="margin-bottom: 20px; color: #666;">Archive this marriage record? It will be hidden from active records but kept in Archives.</p>
            <form id="deleteForm" method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" id="deleteRecordId" name="record_id" value="">
                <div class="form-group"><label>Archive reason *</label><textarea name="archive_reason" required minlength="5"></textarea></div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-delete" style="padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; background: #d7ad43; color: #181204;">Archive</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/components.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Open Add Modal Function - Documents this helper's role in the parish management workflow.
        function openAddModal() {
            document.getElementById('recordForm').reset();
            document.getElementById('actionInput').value = 'add';
            document.getElementById('recordIdInput').value = '';
            document.getElementById('modalTitle').textContent = 'Add Marriage Record';
            document.getElementById('recordModal').classList.add('show');
        }

        // Open Edit Modal Function - Documents this helper's role in the parish management workflow.
        function openEditModal(record) {
            document.getElementById('recordIdInput').value = record.id || '';
            document.getElementById('registryNo').value = record.registry_no || '';
            document.getElementById('bookNo').value = record.book_no || '';
            document.getElementById('pageNo').value = record.page_no || '';
            document.getElementById('entryNo').value = record.entry_no || '';
            document.getElementById('husbandBirthDate').value = record.husband_birth_date || '';
            document.getElementById('wifeBirthDate').value = record.wife_birth_date || '';
            document.getElementById('weddingLocation').value = record.wedding_location || '';
            document.getElementById('husbandName').value = record.husband_name || '';
            document.getElementById('husbandStatus').value = record.husband_status || '';
            document.getElementById('husbandAge').value = record.husband_age || '';
            document.getElementById('husbandBirthOrigin').value = record.husband_birth_origin || '';
            document.getElementById('husbandResidence').value = record.husband_residence || '';
            document.getElementById('husbandParents').value = record.husband_parents || '';
            document.getElementById('wifeName').value = record.wife_name || '';
            document.getElementById('wifeStatus').value = record.wife_status || '';
            document.getElementById('wifeAge').value = record.wife_age || '';
            document.getElementById('wifeBirthOrigin').value = record.wife_birth_origin || '';
            document.getElementById('wifeResidence').value = record.wife_residence || '';
            document.getElementById('wifeParents').value = record.wife_parents || '';
            document.getElementById('weddingDate').value = record.wedding_date || '';
            document.getElementById('sponsors').value = record.sponsors || '';
            document.getElementById('witnessesResidence').value = record.witnesses_residence || '';
            document.getElementById('officiatingPriest').value = record.officiating_priest || '';
            document.getElementById('remarks').value = record.remarks || '';
            document.getElementById('parishPriest').value = record.parish_priest || '';
            document.getElementById('parishSecretary').value = record.parish_secretary || '';
            document.getElementById('recordStatus').value = record.status || 'active';
            document.getElementById('requestId').value = record.request_id || '';
            document.getElementById('actionInput').value = 'edit';
            document.getElementById('modalTitle').textContent = 'Edit Marriage Record';
            document.getElementById('recordModal').classList.add('show');
        }

        // Close Modal Function - Documents this helper's role in the parish management workflow.
        function closeModal() {
            document.getElementById('recordModal').classList.remove('show');
        }

        // Confirm Archive Function - Documents this helper's role in the parish management workflow.
        function confirmArchive(id) {
            document.getElementById('deleteRecordId').value = id;
            document.getElementById('deleteModal').classList.add('show');
        }

        // Close Delete Modal Function - Documents this helper's role in the parish management workflow.
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }

        // Perform Search Function - Documents this helper's role in the parish management workflow.
        function performSearch() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            window.location.href = `?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&page=1`;
        }

        // Apply Filter Function - Documents this helper's role in the parish management workflow.
        function applyFilter() {
            performSearch();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('recordModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === modal) {
                modal.classList.remove('show');
            }
            if (event.target === deleteModal) {
                deleteModal.classList.remove('show');
            }
        }

        // Allow Enter key in search to perform search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
    </script>
</body>
</html>
