<?php
/**
 * FUNERAL RECORDS MANAGEMENT
 * Admin page for managing funeral and burial parish records.
 */

include '../config/security.php';
include '../includes/session.php';
include '../includes/helpers.php';
include '../database/config.php';
require_once '../services/SacramentalRecordService.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/ParishSystem/');
}

requireAdmin();
requirePermission('records.manage');

// Funeral Fetch All Assoc Function - Documents this helper's role in the parish management workflow.
function funeral_fetch_all_assoc($stmt) {
    $result = $stmt->get_result();
    if (!$result) {
        return array();
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

// Ensure Funeral Records Schema Function - Documents this helper's role in the parish management workflow.
function ensure_funeral_records_schema($conn) {
    return requireSchemaColumns($conn, 'funeral_records', [
        'funeral_id', 'request_id', 'registry_no', 'deceased_name', 'family_name',
        'date_of_death', 'date_of_burial', 'civil_status', 'funeral_rites',
        'cause_of_death', 'place_of_burial', 'minister', 'remarks', 'status',
        'created_at', 'updated_at'
    ], 'funeral records');
}

// Funeral Format Date Function - Documents this helper's role in the parish management workflow.
function funeral_format_date($date_value, $format = 'M d, Y') {
    if (empty($date_value) || $date_value === '0000-00-00') {
        return 'N/A';
    }

    return date($format, strtotime($date_value));
}

// Funeral Js Value Function - Documents this helper's role in the parish management workflow.
function funeral_js_value($value) {
    return htmlspecialchars(json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
}

ensure_funeral_records_schema($conn);

$action = $_POST['action'] ?? null;
$message = '';
$alert_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add','edit','archive','restore'], true)) {
    requireValidCsrfToken();
    try {
        $records=new SacramentalRecordService($conn);$actor=(int)($_SESSION['user_id']??0);
        if($action==='add'){$records->create('funeral',$_POST,$actor);$notice='Funeral record created.';}
        elseif($action==='edit'){$records->requestCorrection('funeral',(int)($_POST['record_id']??0),$_POST,(string)($_POST['correction_reason']??''),$actor);$notice='Correction submitted for review; the official record was not overwritten.';}
        elseif($action==='archive'){$records->archive('funeral',(int)($_POST['record_id']??0),(string)($_POST['archive_reason']??''),$actor);$notice='Funeral record archived.';}
        else{$records->restore('funeral',(int)($_POST['record_id']??0),$actor);$notice='Funeral record restored.';}
        redirectWithNotification('funeral-records.php',$notice,'success');
    } catch(Throwable $exception){$message=$exception->getMessage();$alert_type='danger';$action=null;}
}

if (($action === 'add' || $action === 'edit') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    $registry_no = trim($_POST['registry_no'] ?? '');
    $deceased_name = trim($_POST['deceased_name'] ?? '');
    $family_name = trim($_POST['family_name'] ?? '');
    $date_of_death = !empty($_POST['date_of_death']) ? $_POST['date_of_death'] : null;
    $date_of_burial = !empty($_POST['date_of_burial']) ? $_POST['date_of_burial'] : null;
    $civil_status = trim($_POST['civil_status'] ?? '');
    $funeral_rites = trim($_POST['funeral_rites'] ?? '');
    $cause_of_death = trim($_POST['cause_of_death'] ?? '');
    $place_of_burial = trim($_POST['place_of_burial'] ?? '');
    $minister = trim($_POST['minister'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $request_id = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;

    if ($deceased_name && $date_of_burial) {
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO funeral_records (request_id, registry_no, deceased_name, family_name, date_of_death, date_of_burial, civil_status, funeral_rites, cause_of_death, place_of_burial, minister, remarks, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("issssssssssss", $request_id, $registry_no, $deceased_name, $family_name, $date_of_death, $date_of_burial, $civil_status, $funeral_rites, $cause_of_death, $place_of_burial, $minister, $remarks, $status);
                if ($stmt->execute()) {
                    $message = "Funeral record added successfully!";
                    $alert_type = "success";
                } else {
                    $message = "Error adding record: " . $stmt->error;
                    $alert_type = "danger";
                }
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("UPDATE funeral_records SET request_id=?, registry_no=?, deceased_name=?, family_name=?, date_of_death=?, date_of_burial=?, civil_status=?, funeral_rites=?, cause_of_death=?, place_of_burial=?, minister=?, remarks=?, status=? WHERE funeral_id=?");
            if ($stmt) {
                $stmt->bind_param("issssssssssssi", $request_id, $registry_no, $deceased_name, $family_name, $date_of_death, $date_of_burial, $civil_status, $funeral_rites, $cause_of_death, $place_of_burial, $minister, $remarks, $status, $record_id);
                if ($stmt->execute()) {
                    $message = "Funeral record updated successfully!";
                    $alert_type = "success";
                } else {
                    $message = "Error updating record: " . $stmt->error;
                    $alert_type = "danger";
                }
                $stmt->close();
            }
        }
    } else {
        $message = "Please enter the deceased name and date of burial.";
        $alert_type = "warning";
    }
}

if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $record_id = (int)($_POST['record_id'] ?? 0);
    if ($record_id) {
        $stmt = $conn->prepare("UPDATE funeral_records SET status='archived', updated_at=NOW() WHERE funeral_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $record_id);
            if ($stmt->execute()) {
                $message = "Funeral record archived successfully!";
                $alert_type = "success";
            } else {
                $message = "Error archiving record: " . $stmt->error;
                $alert_type = "danger";
            }
            $stmt->close();
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

$where_clauses = array("1=1");
$params = array();
$param_types = "";

if ($search !== '') {
    $where_clauses[] = "(registry_no LIKE ? OR deceased_name LIKE ? OR family_name LIKE ? OR civil_status LIKE ? OR funeral_rites LIKE ? OR cause_of_death LIKE ? OR place_of_burial LIKE ? OR minister LIKE ? OR remarks LIKE ?)";
    $search_param = "%$search%";
    for ($i = 0; $i < 9; $i++) {
        $params[] = $search_param;
        $param_types .= "s";
    }
}

if ($status_filter !== '') {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

$where = implode(" AND ", $where_clauses);

$total_records = 0;
$count_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM funeral_records WHERE $where");
if ($count_stmt) {
    if (!empty($params)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    $count_stmt->execute();
    $count_rows = funeral_fetch_all_assoc($count_stmt);
    $total_records = (int)($count_rows[0]['count'] ?? 0);
    $count_stmt->close();
}

$total_pages = (int)ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

$records = array();
$list_params = $params;
$list_types = $param_types;
$list_params[] = $per_page;
$list_params[] = $offset;
$list_types .= "ii";

$stmt = $conn->prepare("SELECT * FROM funeral_records WHERE $where ORDER BY date_of_burial DESC, funeral_id DESC LIMIT ? OFFSET ?");
if ($stmt) {
    $stmt->bind_param($list_types, ...$list_params);
    $stmt->execute();
    $records = funeral_fetch_all_assoc($stmt);
    $stmt->close();
}

$requests_list = array();
$req_stmt = $conn->prepare("SELECT request_id, reference_number FROM requests WHERE request_type = 'burial_reservation' ORDER BY date_requested DESC");
if ($req_stmt) {
    $req_stmt->execute();
    $requests_list = funeral_fetch_all_assoc($req_stmt);
    $req_stmt->close();
}

$page_title = 'Funeral Records - Parish Management';
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
            --primary-gold: #d4af37;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8eef5 100%);
            font-family: 'Inter', sans-serif;
        }

        .admin-content {
            margin-left: 280px;
            padding: 20px 24px;
            transition: margin-left 0.3s;
        }

        body.admin-sidebar-collapsed .admin-content {
            margin-left: 88px;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 800;
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
            animation: fadeInUp 0.6s ease-out;
        }

        .section-title {
            font-size: 1.35rem;
            font-weight: 700;
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
            font-weight: 700;
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

        .search-bar input,
        .search-bar select {
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
            font-weight: 700;
            color: var(--primary-navy);
            text-align: left;
            font-size: 0.86rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .records-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            color: #5f6b7a;
            vertical-align: top;
        }

        .text-strong {
            color: var(--primary-navy);
            font-weight: 800;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-active {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-archived {
            background: #f5f5f5;
            color: #777;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            white-space: nowrap;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
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
            inset: 0;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal.show {
            display: block;
        }

        .modal-content {
            background-color: white;
            margin: 4% auto;
            padding: 30px;
            border: 1px solid #ccc;
            border-radius: 12px;
            width: 92%;
            max-width: 980px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-navy);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 700;
            color: var(--primary-navy);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .form-group textarea {
            min-height: 90px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
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
            font-weight: 700;
        }

        .btn-save {
            background: #004085;
            color: white;
        }

        .btn-cancel {
            background: #e0e0e0;
            color: #555;
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

            .search-bar,
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
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body>
    <div style="display: flex;">
        <?php include '../includes/admin-sidebar.php'; ?>

        <div class="admin-content">
            <div style="margin-bottom: 30px;">
                <a href="manage-records.php" class="btn btn-primary-gold" style="margin-bottom: 14px;">
                    <i class="fas fa-arrow-left"></i> Back to Sacramental Records
                </a>
                <h1 class="page-title">
                    <i class="fas fa-book-open"></i> Funeral Records
                </h1>
                <p style="color: #6c757d;">Manage funeral and burial registry entries</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo htmlspecialchars($alert_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="card-section">
                <div class="search-bar">
                    <input type="text" id="searchInput" placeholder="Search deceased name, family name, burial place, minister, cause, or remarks..." value="<?php echo htmlspecialchars($search); ?>">
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

            <div class="card-section">
                <div class="section-title">
                    <i class="fas fa-table"></i> Funeral Records (<?php echo $total_records; ?> total)
                </div>
                <div style="overflow-x: auto;">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Deceased Name</th>
                                <th>Family Name</th>
                                <th>Date of Death</th>
                                <th>Date of Burial</th>
                                <th>Civil Status</th>
                                <th>Funeral Rites</th>
                                <th>Cause of Death</th>
                                <th>Place of Burial</th>
                                <th>Minister Name</th>
                                <th>Remarks</th>
                                <th>Record Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) > 0): ?>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                        $record_payload = array(
                                            'id' => $record['funeral_id'],
                                            'registry_no' => $record['registry_no'] ?? '',
                                            'deceased_name' => $record['deceased_name'] ?? '',
                                            'family_name' => $record['family_name'] ?? '',
                                            'date_of_death' => $record['date_of_death'] ?? '',
                                            'date_of_burial' => $record['date_of_burial'] ?? '',
                                            'civil_status' => $record['civil_status'] ?? '',
                                            'funeral_rites' => $record['funeral_rites'] ?? '',
                                            'cause_of_death' => $record['cause_of_death'] ?? '',
                                            'place_of_burial' => $record['place_of_burial'] ?? '',
                                            'minister' => $record['minister'] ?? '',
                                            'remarks' => $record['remarks'] ?? '',
                                            'status' => $record['status'] ?? 'active',
                                            'request_id' => $record['request_id'] ?? '',
                                            'book_no' => $record['book_no'] ?? '', 'page_no' => $record['page_no'] ?? '', 'entry_no' => $record['entry_no'] ?? '', 'birth_date' => $record['birth_date'] ?? ''
                                        );
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($record['registry_no'] ?: $record['funeral_id']); ?></td>
                                        <td><span class="text-strong"><?php echo htmlspecialchars($record['deceased_name']); ?></span></td>
                                        <td><?php echo htmlspecialchars($record['family_name'] ?: 'N/A'); ?></td>
                                        <td><?php echo funeral_format_date($record['date_of_death']); ?></td>
                                        <td><?php echo funeral_format_date($record['date_of_burial']); ?></td>
                                        <td><?php echo htmlspecialchars($record['civil_status'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['funeral_rites'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['cause_of_death'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['place_of_burial'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['minister'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['remarks'] ?: ''); ?></td>
                                        <td>
                                            <span class="status-badge badge-<?php echo strtolower($record['status']); ?>">
                                                <?php echo ucfirst($record['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-edit" onclick="openEditModal(<?php echo funeral_js_value($record_payload); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="action-btn btn-delete" onclick="confirmArchive(<?php echo $record['funeral_id']; ?>)">
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
                                        No funeral records found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div style="margin-top: 20px; text-align: center;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"
                               style="padding: 8px 12px; margin: 0 3px; border-radius: 6px; text-decoration: none; background: <?php echo $i === $page ? 'var(--primary-gold)' : '#e0e0e0'; ?>; color: <?php echo $i === $page ? 'var(--primary-navy)' : '#666'; ?>; font-weight: 700;">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="recordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modalTitle">Add Funeral Record</span>
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
                        <label>Link to Burial Request</label>
                        <select id="requestId" name="request_id">
                            <option value="">-- No Request --</option>
                            <?php foreach ($requests_list as $req): ?>
                                <option value="<?php echo $req['request_id']; ?>">
                                    <?php echo htmlspecialchars($req['reference_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Deceased Name *</label>
                        <input type="text" id="deceasedName" name="deceased_name" required>
                    </div>
                    <div class="form-group"><label>Birth Date *</label><input id="birthDate" type="date" name="birth_date" required max="<?php echo date('Y-m-d'); ?>"></div>

                    <div class="form-group">
                        <label>Family Name</label>
                        <input type="text" id="familyName" name="family_name" placeholder="Family / surname">
                    </div>

                    <div class="form-group">
                        <label>Date of Death *</label>
                        <input type="date" id="dateOfDeath" name="date_of_death" required>
                    </div>

                    <div class="form-group">
                        <label>Date of Burial *</label>
                        <input type="date" id="dateOfBurial" name="date_of_burial" required>
                    </div>

                    <div class="form-group">
                        <label>Civil Status</label>
                        <input type="text" id="civilStatus" name="civil_status" placeholder="Single, married, widowed...">
                    </div>

                    <div class="form-group">
                        <label>Funeral Rites</label>
                        <input type="text" id="funeralRites" name="funeral_rites" placeholder="Mass, blessing, burial rites...">
                    </div>

                    <div class="form-group">
                        <label>Cause of Death</label>
                        <input type="text" id="causeOfDeath" name="cause_of_death">
                    </div>

                    <div class="form-group">
                        <label>Place of Burial *</label>
                        <input type="text" id="placeOfBurial" name="place_of_burial" required>
                    </div>

                    <div class="form-group">
                        <label>Minister Name *</label>
                        <input type="text" id="minister" name="minister" placeholder="Priest / minister" required>
                    </div>

                    <div class="form-group">
                        <label>Record Status</label>
                        <select id="recordStatus" name="status">
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Remarks</label>
                        <textarea id="remarks" name="remarks" placeholder="Additional remarks"></textarea>
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

    <div id="archiveModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <span>Confirm Archive</span>
                <span onclick="closeArchiveModal()" style="cursor: pointer; font-size: 1.5rem; color: #999;">&times;</span>
            </div>
            <p style="margin-bottom: 20px; color: #666;">Archive this funeral record? It will be hidden from active records but kept in Archives.</p>
            <form method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" id="archiveRecordId" name="record_id" value="">
                <div class="form-group"><label>Archive reason *</label><textarea name="archive_reason" required minlength="5"></textarea></div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeArchiveModal()">Cancel</button>
                    <button type="submit" class="btn-delete" style="padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; background: #d7ad43; color: #181204;">Archive</button>
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
            document.getElementById('modalTitle').textContent = 'Add Funeral Record';
            document.getElementById('recordModal').classList.add('show');
        }

        // Open Edit Modal Function - Documents this helper's role in the parish management workflow.
        function openEditModal(record) {
            document.getElementById('recordIdInput').value = record.id || '';
            document.getElementById('registryNo').value = record.registry_no || '';
            document.getElementById('bookNo').value = record.book_no || '';
            document.getElementById('pageNo').value = record.page_no || '';
            document.getElementById('entryNo').value = record.entry_no || '';
            document.getElementById('birthDate').value = record.birth_date || '';
            document.getElementById('deceasedName').value = record.deceased_name || '';
            document.getElementById('familyName').value = record.family_name || '';
            document.getElementById('dateOfDeath').value = record.date_of_death || '';
            document.getElementById('dateOfBurial').value = record.date_of_burial || '';
            document.getElementById('civilStatus').value = record.civil_status || '';
            document.getElementById('funeralRites').value = record.funeral_rites || '';
            document.getElementById('causeOfDeath').value = record.cause_of_death || '';
            document.getElementById('placeOfBurial').value = record.place_of_burial || '';
            document.getElementById('minister').value = record.minister || '';
            document.getElementById('remarks').value = record.remarks || '';
            document.getElementById('recordStatus').value = record.status || 'active';
            document.getElementById('requestId').value = record.request_id || '';
            document.getElementById('actionInput').value = 'edit';
            document.getElementById('modalTitle').textContent = 'Edit Funeral Record';
            document.getElementById('recordModal').classList.add('show');
        }

        // Close Modal Function - Documents this helper's role in the parish management workflow.
        function closeModal() {
            document.getElementById('recordModal').classList.remove('show');
        }

        // Confirm Archive Function - Documents this helper's role in the parish management workflow.
        function confirmArchive(id) {
            document.getElementById('archiveRecordId').value = id;
            document.getElementById('archiveModal').classList.add('show');
        }

        // Close Archive Modal Function - Documents this helper's role in the parish management workflow.
        function closeArchiveModal() {
            document.getElementById('archiveModal').classList.remove('show');
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

        window.onclick = function(event) {
            const recordModal = document.getElementById('recordModal');
            const archiveModal = document.getElementById('archiveModal');
            if (event.target === recordModal) {
                recordModal.classList.remove('show');
            }
            if (event.target === archiveModal) {
                archiveModal.classList.remove('show');
            }
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
    </script>
</body>
</html>
