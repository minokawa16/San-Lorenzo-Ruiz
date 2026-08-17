<?php
/**
 * My Requests Module - Lists parish service requests submitted by the signed-in user.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$breadcrumbs = [
    'Dashboard' => 'index.php',
    'My Requests' => null
];

$user_id = $_SESSION['user_id'];
$page = intval($_GET['page'] ?? 1);
$limit = 10;
$search = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$allowed_statuses = ['pending', 'approved', 'processing', 'completed', 'rejected'];
$status_filter = in_array($status_filter, $allowed_statuses, true) ? $status_filter : '';
$search_like = '%' . $search . '%';

$where = ['user_id = ?'];
$types = 'i';
$params = [$user_id];

if ($status_filter !== '') {
    $where[] = 'status = ?';
    $types .= 's';
    $params[] = $status_filter;
}

if ($search !== '') {
    $where[] = '(reference_number LIKE ? OR request_type LIKE ? OR status LIKE ? OR description LIKE ?)';
    $types .= 'ssss';
    array_push($params, $search_like, $search_like, $search_like, $search_like);
}

$where_sql = implode(' AND ', $where);

$total = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM requests WHERE $where_sql");
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_result = $stmt->get_result();
    $total = intval(($total_result->fetch_assoc())['count'] ?? 0);
    $stmt->close();
}
$pagination = getPaginationData($page, $limit, $total);

// Get requests
$requests = [];
$list_types = $types . 'ii';
$list_params = array_merge($params, [$pagination['offset'], $pagination['limit']]);
$stmt = $conn->prepare("SELECT * FROM requests WHERE $where_sql ORDER BY date_requested DESC LIMIT ?, ?");
if ($stmt) {
    $stmt->bind_param($list_types, ...$list_params);
}

if (isset($stmt) && $stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();
}

$page_title = 'My Requests';
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/breadcrumb.php'; ?>
<?php include '../includes/back_button.php'; ?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="mb-2"><i class="fas fa-list"></i> My Requests</h1>
        </div>
        <div class="col-md-4 text-end">
            <a href="request-certificate.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Request
            </a>
        </div>
    </div>

    <form class="card card-body mb-3" method="GET" action="">
        <div class="row g-2 align-items-center">
            <div class="col-md-6">
                <input type="text" class="form-control" name="q" value="<?php echo e($search); ?>" placeholder="Search by reference, type, status, or description">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($allowed_statuses as $status_option): ?>
                        <option value="<?php echo e($status_option); ?>" <?php echo $status_filter === $status_option ? 'selected' : ''; ?>>
                            <?php echo e(ucfirst($status_option)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-grid d-md-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Search</button>
                <?php if ($search !== '' || $status_filter !== ''): ?>
                    <a class="btn btn-outline-secondary" href="my-requests.php">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body">
            <?php if (!empty($requests)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Reference #</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Date Requested</th>
                                <th>Last Updated</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td data-label="Reference"><strong><?php echo e($request['reference_number']); ?></strong></td>
                                    <td data-label="Request Type"><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></td>
                                    <td data-label="Status">
                                        <span class="badge bg-<?php echo getStatusBadgeClass($request['status']); ?>">
                                            <?php echo e(ucfirst($request['status'])); ?>
                                        </span>
                                    </td>
                                    <td data-label="Date Requested"><?php echo formatDate($request['date_requested']); ?></td>
                                    <td data-label="Last Updated"><?php echo formatDate($request['updated_at']); ?></td>
                                    <td data-label="Action">
                                        <a href="view-request.php?id=<?php echo $request['request_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> You have not made any requests yet.
                    <a href="request-certificate.php" class="alert-link">Submit your first request</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
