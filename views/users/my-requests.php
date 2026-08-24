<?php require dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php require dirname(__DIR__, 2) . '/includes/breadcrumb.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/back_button.php'; ?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-8"><h1 class="mb-2"><i class="fas fa-list"></i> My Requests</h1></div>
        <div class="col-md-4 text-end">
            <a href="request-certificate.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Request</a>
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
                                <th>Reference #</th><th>Type</th><th>Status</th>
                                <th>Date Requested</th><th>Last Updated</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td data-label="Reference"><strong><?php echo e($request['reference_number']); ?></strong></td>
                                    <td data-label="Request Type"><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></td>
                                    <td data-label="Status"><span class="badge bg-<?php echo getStatusBadgeClass($request['status']); ?>"><?php echo e(ucfirst($request['status'])); ?></span></td>
                                    <td data-label="Date Requested"><?php echo formatDate($request['date_requested']); ?></td>
                                    <td data-label="Last Updated"><?php echo formatDate($request['updated_at']); ?></td>
                                    <td data-label="Action">
                                        <a href="view-request.php?id=<?php echo (int) $request['request_id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pagination['total_pages'] > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&amp;q=<?php echo urlencode($search); ?>&amp;status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
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

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
