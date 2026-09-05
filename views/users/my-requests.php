<?php require dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php require dirname(__DIR__, 2) . '/includes/breadcrumb.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/back_button.php'; ?>

<div class="container-fluid mt-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1 fw-bold text-dark" style="font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.5rem, 2.2vw, 2rem);">
                <i class="fas fa-list-check me-2" style="color: #C89B3C;"></i> My Requests
            </h1>
            <p class="text-muted mb-0" style="font-size: 0.9rem;">Track all your sacrament, blessing, and certificate requests in real time.</p>
        </div>
        <div>
            <a href="request-certificate.php" class="btn text-white fw-semibold px-3 py-2 shadow-sm" style="background: #C89B3C; border-color: #A97F24; border-radius: 10px;">
                <i class="fas fa-plus me-1"></i> Submit New Request
            </a>
        </div>
    </div>

    <form class="card border-0 shadow-sm mb-4" method="GET" action="" style="border: 1px solid #E8E1D5 !important; border-radius: 12px; background: #FFFFFF;">
        <div class="card-body p-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0" style="border-color: #E8E1D5; color: #9A733B;"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control border-start-0 ps-0" name="q" value="<?php echo e($search); ?>" placeholder="Search by reference, request type, or details..." style="border-color: #E8E1D5; background: #FAF7F2;">
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="status" style="border-color: #E8E1D5; background: #FAF7F2;">
                        <option value="">All Statuses</option>
                        <?php foreach ($allowed_statuses as $status_option): ?>
                            <option value="<?php echo e($status_option); ?>" <?php echo $status_filter === $status_option ? 'selected' : ''; ?>>
                                <?php echo e(ucfirst($status_option)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-grid d-md-flex gap-2">
                    <button class="btn text-white fw-semibold px-3 flex-grow-1" type="submit" style="background: #2E3A2D; border-color: #263225; border-radius: 8px;">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <?php if ($search !== '' || $status_filter !== ''): ?>
                        <a class="btn btn-outline-secondary" href="my-requests.php" style="border-radius: 8px;">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm" style="border: 1px solid #E8E1D5 !important; border-radius: 14px; background: #FFFFFF;">
        <div class="card-body p-0">
            <?php if (!empty($requests)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead style="background: #FAF7F2; border-bottom: 1px solid #E8E1D5;">
                            <tr>
                                <th class="py-3 px-4 text-secondary text-uppercase fw-bold" style="font-size: 0.78rem; letter-spacing: 0.5px;">Reference #</th>
                                <th class="py-3 px-3 text-secondary text-uppercase fw-bold" style="font-size: 0.78rem; letter-spacing: 0.5px;">Request Type</th>
                                <th class="py-3 px-3 text-secondary text-uppercase fw-bold" style="font-size: 0.78rem; letter-spacing: 0.5px;">Status</th>
                                <th class="py-3 px-3 text-secondary text-uppercase fw-bold" style="font-size: 0.78rem; letter-spacing: 0.5px;">Date Submitted</th>
                                <th class="py-3 px-3 text-secondary text-uppercase fw-bold" style="font-size: 0.78rem; letter-spacing: 0.5px;">Last Updated</th>
                                <th class="py-3 px-4 text-secondary text-uppercase fw-bold text-end" style="font-size: 0.78rem; letter-spacing: 0.5px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr style="border-bottom: 1px solid #F0ECE4;">
                                    <td class="py-3 px-4" data-label="Reference">
                                        <span class="fw-bold text-dark font-monospace" style="font-size: 0.95rem;"><?php echo e($request['reference_number']); ?></span>
                                    </td>
                                    <td class="py-3 px-3" data-label="Request Type">
                                        <span class="fw-semibold text-dark"><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></span>
                                    </td>
                                    <td class="py-3 px-3" data-label="Status">
                                        <span class="badge bg-<?php echo getStatusBadgeClass($request['status']); ?> px-2 py-1" style="font-size: 0.78rem; font-weight: 600;">
                                            <?php echo e(ucfirst(str_replace('_', ' ', $request['status']))); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 text-muted" data-label="Date Requested" style="font-size: 0.88rem;">
                                        <i class="fas fa-calendar-day me-1 text-secondary"></i> <?php echo formatDate($request['date_requested']); ?>
                                    </td>
                                    <td class="py-3 px-3 text-muted" data-label="Last Updated" style="font-size: 0.88rem;">
                                        <i class="fas fa-clock me-1 text-secondary"></i> <?php echo formatDate($request['updated_at']); ?>
                                    </td>
                                    <td class="py-3 px-4 text-end" data-label="Action">
                                        <a href="view-request.php?id=<?php echo (int) $request['request_id']; ?>" class="btn btn-sm btn-outline-secondary px-3 py-1 fw-semibold" style="border-color: #E8E1D5; border-radius: 8px;">
                                            <i class="fas fa-eye me-1" style="color: #C89B3C;"></i> View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="p-3 border-top" style="border-color: #E8E1D5 !important;">
                        <nav>
                            <ul class="pagination justify-content-center mb-0">
                                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&amp;q=<?php echo urlencode($search); ?>&amp;status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="p-5 text-center">
                    <div class="mb-3">
                        <i class="fas fa-clipboard-list fa-3x" style="color: #C89B3C; opacity: 0.7;"></i>
                    </div>
                    <h5 class="fw-bold text-dark">No Requests Found</h5>
                    <p class="text-muted mb-3" style="max-width: 400px; margin: 0 auto; font-size: 0.9rem;">
                        <?php echo $search !== '' || $status_filter !== '' ? 'No requests match your current search or filter criteria.' : 'You have not submitted any sacramental or certificate requests yet.'; ?>
                    </p>
                    <a href="request-certificate.php" class="btn text-white fw-semibold px-4 py-2" style="background: #C89B3C; border-color: #A97F24; border-radius: 10px;">
                        <i class="fas fa-plus me-1"></i> Submit Your First Request
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__, 2) . '/templates/footer.php'; ?>
