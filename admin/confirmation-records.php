<?php
/**
 * CONFIRMATION RECORDS MANAGEMENT
 * Admin page for managing confirmation sacramental records
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

// Confirmation Column Exists Function - Documents this helper's role in the parish management workflow.
function confirmation_column_exists($conn, $column_name) {
    return schemaColumnExists($conn, 'confirmation_records', (string) $column_name);
}

// Ensure Confirmation Record Book Schema Function - Documents this helper's role in the parish management workflow.
function ensure_confirmation_record_book_schema($conn) {
    return requireSchemaColumns($conn, 'confirmation_records', [
        'request_id', 'registry_no', 'age', 'origin_parish', 'origin_province',
        'baptismal_place', 'parents', 'stipend_pesos', 'stipend_cents',
        'observations', 'parish_priest', 'parish_secretary'
    ], 'confirmation records');
}

// Format Record Date Function - Documents this helper's role in the parish management workflow.
function format_record_date($date_value, $format = 'M d, Y') {
    if (empty($date_value) || $date_value === '0000-00-00') {
        return 'N/A';
    }

    return date($format, strtotime($date_value));
}

// Js Value Function - Documents this helper's role in the parish management workflow.
function js_value($value) {
    return htmlspecialchars(json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
}

ensure_confirmation_record_book_schema($conn);

// Handle POST actions
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$message = '';
$alert_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add','edit','archive','restore'], true)) {
    requireValidCsrfToken();
    try {
        $records=new SacramentalRecordService($conn);$actor=(int)($_SESSION['user_id']??0);
        if($action==='add'){$records->create('confirmation',$_POST,$actor);$notice='Confirmation record created.';}
        elseif($action==='edit'){$records->requestCorrection('confirmation',(int)($_POST['record_id']??0),$_POST,(string)($_POST['correction_reason']??''),$actor);$notice='Correction submitted for review; the official record was not overwritten.';}
        elseif($action==='archive'){$records->archive('confirmation',(int)($_POST['record_id']??0),(string)($_POST['archive_reason']??''),$actor);$notice='Confirmation record archived.';}
        else{$records->restore('confirmation',(int)($_POST['record_id']??0),$actor);$notice='Confirmation record restored.';}
        redirectWithNotification('confirmation-records.php',$notice,'success');
    } catch(Throwable $exception){$message=$exception->getMessage();$alert_type='danger';$action=null;}
}

// Add new confirmation record
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $registry_no = trim($_POST['registry_no'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $birth_date = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $confirmation_date = !empty($_POST['confirmation_date']) ? $_POST['confirmation_date'] : '';
    $confirmation_name = trim($_POST['confirmation_name'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $origin_parish = trim($_POST['origin_parish'] ?? '');
    $origin_province = trim($_POST['origin_province'] ?? '');
    $baptismal_place = trim($_POST['baptismal_place'] ?? '');
    $parents = trim($_POST['parents'] ?? '');
    $sponsor = trim($_POST['sponsor'] ?? '');
    $bishop_priest = trim($_POST['minister'] ?? $_POST['bishop_priest'] ?? '');
    $stipend_pesos = trim($_POST['stipend_pesos'] ?? '');
    $stipend_cents = trim($_POST['stipend_cents'] ?? '');
    $observations = trim($_POST['observations'] ?? '');
    $parish_priest = trim($_POST['parish_priest'] ?? '');
    $parish_secretary = trim($_POST['parish_secretary'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $request_id = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;

    if ($fullname && $confirmation_date) {
        $stmt = $conn->prepare("INSERT INTO confirmation_records (registry_no, fullname, birth_date, confirmation_date, confirmation_name, age, origin_parish, origin_province, baptismal_place, parents, sponsor, bishop_priest, stipend_pesos, stipend_cents, observations, parish_priest, parish_secretary, status, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssssssssssssssssssi", $registry_no, $fullname, $birth_date, $confirmation_date, $confirmation_name, $age, $origin_parish, $origin_province, $baptismal_place, $parents, $sponsor, $bishop_priest, $stipend_pesos, $stipend_cents, $observations, $parish_priest, $parish_secretary, $status, $request_id);
            if ($stmt->execute()) {
                $message = "Confirmation record added successfully!";
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

// Update confirmation record
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    $registry_no = trim($_POST['registry_no'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $birth_date = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $confirmation_date = !empty($_POST['confirmation_date']) ? $_POST['confirmation_date'] : '';
    $confirmation_name = trim($_POST['confirmation_name'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $origin_parish = trim($_POST['origin_parish'] ?? '');
    $origin_province = trim($_POST['origin_province'] ?? '');
    $baptismal_place = trim($_POST['baptismal_place'] ?? '');
    $parents = trim($_POST['parents'] ?? '');
    $sponsor = trim($_POST['sponsor'] ?? '');
    $bishop_priest = trim($_POST['minister'] ?? $_POST['bishop_priest'] ?? '');
    $stipend_pesos = trim($_POST['stipend_pesos'] ?? '');
    $stipend_cents = trim($_POST['stipend_cents'] ?? '');
    $observations = trim($_POST['observations'] ?? '');
    $parish_priest = trim($_POST['parish_priest'] ?? '');
    $parish_secretary = trim($_POST['parish_secretary'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $request_id = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;

    if ($record_id && $fullname && $confirmation_date) {
        $stmt = $conn->prepare("UPDATE confirmation_records SET registry_no=?, fullname=?, birth_date=?, confirmation_date=?, confirmation_name=?, age=?, origin_parish=?, origin_province=?, baptismal_place=?, parents=?, sponsor=?, bishop_priest=?, stipend_pesos=?, stipend_cents=?, observations=?, parish_priest=?, parish_secretary=?, status=?, request_id=? WHERE confirmation_id=?");
        if ($stmt) {
            $stmt->bind_param("ssssssssssssssssssii", $registry_no, $fullname, $birth_date, $confirmation_date, $confirmation_name, $age, $origin_parish, $origin_province, $baptismal_place, $parents, $sponsor, $bishop_priest, $stipend_pesos, $stipend_cents, $observations, $parish_priest, $parish_secretary, $status, $request_id, $record_id);
            if ($stmt->execute()) {
                $message = "Confirmation record updated successfully!";
                $alert_type = "success";
            } else {
                $message = "Error updating record: " . $stmt->error;
                $alert_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Archive confirmation record
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    if ($record_id) {
        $stmt = $conn->prepare("UPDATE confirmation_records SET status='archived', updated_at=NOW() WHERE confirmation_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $record_id);
            if ($stmt->execute()) {
                $message = "Confirmation record archived successfully!";
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
    $where_clauses[] = "(fullname LIKE ? OR registry_no LIKE ? OR confirmation_name LIKE ? OR origin_parish LIKE ? OR origin_province LIKE ? OR baptismal_place LIKE ? OR parents LIKE ? OR sponsor LIKE ? OR bishop_priest LIKE ?)";
    $search_param = "%$search%";
    for ($i = 0; $i < 9; $i++) {
        $params[] = $search_param;
        $param_types .= "s";
    }
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

$where = implode(" AND ", $where_clauses);

// Get total count
$count_query = "SELECT COUNT(*) as count FROM confirmation_records WHERE $where";
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
$query = "SELECT * FROM confirmation_records WHERE $where ORDER BY confirmation_date DESC LIMIT ? OFFSET ?";
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

$page_title = 'Confirmation Records';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Sacramental Records' => 'manage-records.php',
    'Confirmation Records' => null
];

include '../templates/header.php';
?>
    <style>
        :root {
            --primary-navy: #1a1f3a;
            --primary-royal-blue: #004085;
            --primary-gold: #d4af37;
            --status-success: #28a745;
            --status-warning: #ffc107;
            --status-danger: #dc3545;
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
            white-space: nowrap;
        }

        .records-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            color: #6c757d;
            vertical-align: top;
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
            margin: 5% auto;
            padding: 30px;
            border: 1px solid #ccc;
            border-radius: 12px;
            width: 90%;
            max-width: 980px;
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

        .modal-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
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
            min-height: 86px;
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

            .modal-form-grid {
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
    <div class="container-fluid px-0">
            <!-- Standardized Page Header -->
            <?php
            $page_header_title = 'Confirmation Records';
            $page_header_subtitle = 'Manage and digitize confirmation sacramental registry entries.';
            $page_header_icon = 'fa-dove';
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
                    <input type="text" id="searchInput" placeholder="Search by full name or confirmation name..." value="<?php echo htmlspecialchars($search); ?>">
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
                    <i class="fas fa-table"></i> Confirmation Records (<?php echo $total_records; ?> total)
                </div>
                <div style="overflow-x: auto;">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Confirmation Year</th>
                                <th>Month and Day</th>
                                <th>Name of Confirmed Person</th>
                                <th>Age</th>
                                <th>Parish of Origin</th>
                                <th>Province</th>
                                <th>Place of Baptism</th>
                                <th>Parents</th>
                                <th>Sponsor / Godparent</th>
                                <th>Stipend (Pesos)</th>
                                <th>Stipend (Cents)</th>
                                <th>Minister Name</th>
                                <th>Remarks / Observations</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) > 0): ?>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                        $record_payload = array(
                                            'id' => $record['confirmation_id'],
                                            'registry_no' => $record['registry_no'] ?? '',
                                            'fullname' => $record['fullname'] ?? '',
                                            'birth_date' => $record['birth_date'] ?? '',
                                            'confirmation_date' => $record['confirmation_date'] ?? '',
                                            'confirmation_name' => $record['confirmation_name'] ?? '',
                                            'age' => $record['age'] ?? '',
                                            'origin_parish' => $record['origin_parish'] ?? '',
                                            'origin_province' => $record['origin_province'] ?? '',
                                            'baptismal_place' => $record['baptismal_place'] ?? '',
                                            'parents' => $record['parents'] ?? '',
                                            'sponsor' => $record['sponsor'] ?? '',
                                            'minister' => $record['bishop_priest'] ?? '',
                                            'stipend_pesos' => $record['stipend_pesos'] ?? '',
                                            'stipend_cents' => $record['stipend_cents'] ?? '',
                                            'observations' => $record['observations'] ?? '',
                                            'parish_priest' => $record['parish_priest'] ?? '',
                                            'parish_secretary' => $record['parish_secretary'] ?? '',
                                            'status' => $record['status'] ?? 'active',
                                            'request_id' => $record['request_id'] ?? '',
                                            'book_no' => $record['book_no'] ?? '', 'page_no' => $record['page_no'] ?? '', 'entry_no' => $record['entry_no'] ?? ''
                                        );
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($record['registry_no'] ?: $record['confirmation_id']); ?></td>
                                        <td><?php echo !empty($record['confirmation_date']) ? date('Y', strtotime($record['confirmation_date'])) : 'N/A'; ?></td>
                                        <td><?php echo format_record_date($record['confirmation_date'], 'F d'); ?></td>
                                        <td><strong><?php echo htmlspecialchars($record['fullname']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($record['age'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['origin_parish'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['origin_province'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['baptismal_place'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['parents'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['sponsor'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['stipend_pesos'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($record['stipend_cents'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($record['bishop_priest'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['observations'] ?? ''); ?></td>
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
                                                <button class="action-btn btn-delete" onclick="confirmArchive(<?php echo $record['confirmation_id']; ?>)">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="16" style="text-align: center; padding: 30px; color: #6c757d;">
                                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                                        No confirmation records found.
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
                <span id="modalTitle">Add Confirmation Record</span>
                <span onclick="closeModal()" style="cursor: pointer; font-size: 1.5rem; color: #999;">&times;</span>
            </div>
            <form id="recordForm" method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" id="actionInput" name="action" value="add">
                <input type="hidden" id="recordIdInput" name="record_id" value="">

                <div class="modal-form-grid">
                    <div class="form-group">
                        <label>No.</label>
                        <input type="text" id="registryNo" name="registry_no" placeholder="Record number">
                    </div>
                    <div class="form-group"><label>Book / Page / Entry</label><div style="display:flex;gap:6px"><input id="bookNo" name="book_no" placeholder="Book"><input id="pageNo" name="page_no" placeholder="Page"><input id="entryNo" name="entry_no" placeholder="Entry"></div></div>

                    <div class="form-group">
                        <label>Confirmation Date *</label>
                        <input type="date" id="confirmationDate" name="confirmation_date" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Name of Confirmed Person *</label>
                        <input type="text" id="fullName" name="fullname" required>
                    </div>

                    <div class="form-group">
                        <label>Age</label>
                        <input type="text" id="age" name="age" placeholder="Age">
                    </div>

                    <div class="form-group">
                        <label>Confirmation Name</label>
                        <input type="text" id="confirmationName" name="confirmation_name" placeholder="Optional chosen confirmation name">
                    </div>

                    <div class="form-group">
                        <label>Parish of Origin</label>
                        <input type="text" id="originParish" name="origin_parish" placeholder="Parish of origin">
                    </div>

                    <div class="form-group">
                        <label>Province</label>
                        <input type="text" id="originProvince" name="origin_province" placeholder="Province">
                    </div>

                    <div class="form-group">
                        <label>Place of Baptism</label>
                        <input type="text" id="baptismalPlace" name="baptismal_place" placeholder="Place of baptism">
                    </div>

                    <div class="form-group">
                        <label>Parents *</label>
                        <input type="text" id="parents" name="parents" placeholder="Parents" required>
                    </div>

                    <div class="form-group">
                        <label>Sponsor / Godparent *</label>
                        <input type="text" id="sponsor" name="sponsor" placeholder="Sponsor / godparent" required>
                    </div>

                    <div class="form-group">
                        <label>Minister Name *</label>
                        <input type="text" id="ministerName" name="minister" placeholder="Minister / bishop / priest" required>
                    </div>

                    <div class="form-group">
                        <label>Stipend (Pesos)</label>
                        <input type="text" id="stipendPesos" name="stipend_pesos">
                    </div>

                    <div class="form-group">
                        <label>Stipend (Cents)</label>
                        <input type="text" id="stipendCents" name="stipend_cents">
                    </div>

                    <div class="form-group">
                        <label>Birth Date *</label>
                        <input type="date" id="birthDate" name="birth_date" required max="<?php echo date('Y-m-d'); ?>">
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

                    <div class="form-group full-width">
                        <label>Remarks / Observations</label>
                        <textarea id="observations" name="observations" placeholder="Observations / remarks"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Parish Priest</label>
                        <input type="text" id="parishPriest" name="parish_priest" placeholder="Name printed above Parish Priest">
                    </div>

                    <div class="form-group">
                        <label>Parish Secretary</label>
                        <input type="text" id="parishSecretary" name="parish_secretary" placeholder="Name printed above Parish Secretary">
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
            <p style="margin-bottom: 20px; color: #666;">Archive this confirmation record? It will be hidden from active records but kept in Archives.</p>
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
            document.getElementById('modalTitle').textContent = 'Add Confirmation Record';
            document.getElementById('recordModal').classList.add('show');
        }

        // Open Edit Modal Function - Documents this helper's role in the parish management workflow.
        function openEditModal(record) {
            document.getElementById('recordIdInput').value = record.id || '';
            document.getElementById('registryNo').value = record.registry_no || '';
            document.getElementById('bookNo').value = record.book_no || '';
            document.getElementById('pageNo').value = record.page_no || '';
            document.getElementById('entryNo').value = record.entry_no || '';
            document.getElementById('fullName').value = record.fullname || '';
            document.getElementById('birthDate').value = record.birth_date || '';
            document.getElementById('confirmationDate').value = record.confirmation_date || '';
            document.getElementById('confirmationName').value = record.confirmation_name || '';
            document.getElementById('age').value = record.age || '';
            document.getElementById('originParish').value = record.origin_parish || '';
            document.getElementById('originProvince').value = record.origin_province || '';
            document.getElementById('baptismalPlace').value = record.baptismal_place || '';
            document.getElementById('parents').value = record.parents || '';
            document.getElementById('sponsor').value = record.sponsor || '';
            document.getElementById('ministerName').value = record.minister || '';
            document.getElementById('stipendPesos').value = record.stipend_pesos || '';
            document.getElementById('stipendCents').value = record.stipend_cents || '';
            document.getElementById('observations').value = record.observations || '';
            document.getElementById('parishPriest').value = record.parish_priest || '';
            document.getElementById('parishSecretary').value = record.parish_secretary || '';
            document.getElementById('recordStatus').value = record.status || 'active';
            document.getElementById('requestId').value = record.request_id || '';
            document.getElementById('actionInput').value = 'edit';
            document.getElementById('modalTitle').textContent = 'Edit Confirmation Record';
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
</div>
<?php include '../templates/footer.php'; ?>
