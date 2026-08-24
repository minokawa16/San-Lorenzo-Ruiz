<?php
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/SacramentalRecordService.php';

requireAdmin();
requirePermission('records.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new DomainException('Invalid correction action.');
        }
        (new SacramentalRecordService($conn))->reviewCorrection((int)($_POST['correction_id'] ?? 0), $action === 'approve', (string)($_POST['review_reason'] ?? ''), (int)$_SESSION['user_id'], hasPermission('records.correct_locked'));
        redirectWithNotification('record-corrections.php', $action === 'approve' ? 'Correction approved and applied.' : 'Correction rejected.', 'success');
    } catch (Throwable $e) {
        redirectWithNotification('record-corrections.php', $e->getMessage(), 'error');
    }
}

$requested_status = (string)($_GET['status'] ?? 'pending');
$status = in_array($requested_status, ['pending', 'applied', 'rejected'], true) ? $requested_status : 'pending';

$stmt = $conn->prepare("SELECT c.*, u.fullname requester, COUNT(ch.change_id) change_count FROM sacramental_record_corrections c JOIN users u ON u.id=c.requested_by LEFT JOIN sacramental_correction_changes ch ON ch.correction_id=c.correction_id WHERE c.status=? GROUP BY c.correction_id ORDER BY c.requested_at DESC");
$stmt->bind_param('s', $status);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$changes = [];
if (isset($_GET['view'])) {
    $id = (int)$_GET['view'];
    $stmt = $conn->prepare('SELECT field_name, previous_value, new_value FROM sacramental_correction_changes WHERE correction_id=? ORDER BY change_id');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $changes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$page_title = 'Record Corrections - Parish Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/premium-parish.css') ? filemtime(__DIR__ . '/../assets/css/premium-parish.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/parish-design-system.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/parish-design-system.css') ? filemtime(__DIR__ . '/../assets/css/parish-design-system.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/admin-sidebar.css') ? filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body class="premium-admin">
    <div class="premium-admin-shell">
        <?php include '../includes/admin-sidebar.php'; ?>

        <div class="premium-admin-content pds-page-container">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <a href="manage-records.php" class="btn btn-primary-gold mb-2" style="font-size: 0.82rem;">
                        <i class="fas fa-arrow-left"></i> Back to Sacramental Records
                    </a>
                    <h1 class="page-title" style="font-size: 1.5rem; font-weight: 700; color: #1c1b18; margin: 0;">
                        <i class="fas fa-pen-to-square" style="color: #c89b3c;"></i> Record Correction Review
                    </h1>
                    <p class="text-muted mb-0" style="margin-top: 4px;">Official values change only after this review.</p>
                </div>
            </div>

            <nav class="nav nav-pills mb-3">
                <?php foreach (['pending', 'applied', 'rejected'] as $s): ?>
                    <a class="nav-link <?php echo $status === $s ? 'active' : ''; ?>" href="?status=<?php echo e($s); ?>" style="<?php echo $status === $s ? 'background-color: #c89b3c; color: white;' : ''; ?>">
                        <?php echo e(ucfirst($s)); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="card" style="border-radius: 10px; border: 1px solid #e5e7eb;">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Record</th>
                                <th>Reason</th>
                                <th>Requester</th>
                                <th>Changes</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="6" class="text-center p-4 text-muted">No <?php echo e($status); ?> corrections.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>#<?php echo (int)$r['correction_id']; ?></td>
                                    <td><strong><?php echo e(ucfirst($r['record_type'])); ?> #<?php echo (int)$r['record_id']; ?></strong></td>
                                    <td><?php echo e($r['reason']); ?></td>
                                    <td><?php echo e($r['requester']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo (int)$r['change_count']; ?></span></td>
                                    <td>
                                        <a class="btn btn-sm pds-btn pds-btn-ghost-outline" href="?status=<?php echo e($status); ?>&view=<?php echo (int)$r['correction_id']; ?>">
                                            Review
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($changes): ?>
                <div class="card mt-4" style="border-radius: 10px; border: 1px solid #e5e7eb;">
                    <div class="card-header fw-bold bg-light">Proposed Field Changes</div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Current Value</th>
                                    <th>Proposed Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($changes as $c): ?>
                                    <tr>
                                        <td><strong><?php echo e(str_replace('_', ' ', $c['field_name'])); ?></strong></td>
                                        <td><span class="text-danger"><?php echo e($c['previous_value']); ?></span></td>
                                        <td><span class="text-success"><?php echo e($c['new_value']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($status === 'pending'): ?>
                        <form method="post" class="card-body border-top">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="correction_id" value="<?php echo (int)$_GET['view']; ?>">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Review Note</label>
                                <textarea class="form-control" name="review_reason" maxlength="1000" placeholder="Optional review comments..."></textarea>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-success" name="action" value="approve"><i class="fas fa-check"></i> Approve and Apply</button>
                                <button class="btn btn-danger" name="action" value="reject"><i class="fas fa-times"></i> Reject</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div><!-- /.premium-admin-content -->
    </div><!-- /.premium-admin-shell -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
