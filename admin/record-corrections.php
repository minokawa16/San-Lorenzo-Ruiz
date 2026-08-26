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

$page_title = 'Record Corrections';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Sacramental Records' => 'manage-records.php',
    'Record Corrections' => null
];

include '../templates/header.php';
?>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Record Correction Review';
    $page_header_subtitle = 'Audit and approve requested changes to official sacramental registry records.';
    $page_header_icon = 'fa-pen-to-square';
    $show_back_button = true;
    $back_button_url = 'manage-records.php';
    include '../includes/page_header.php';
    ?>

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

</div>
<?php include '../templates/footer.php'; ?>
