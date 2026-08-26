<?php
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/SacramentalCsvImportService.php';

requireAdmin();
requirePermission('records.manage');

$service = new SacramentalCsvImportService($conn, dirname(__DIR__));
$actor = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        if (($_POST['action'] ?? '') === 'stage') {
            $id = $service->stage((string)($_POST['record_type'] ?? ''), $_FILES['csv'] ?? [], $actor);
            redirectWithNotification('?id=' . $id, 'CSV validated. Review the result before confirming.', 'success');
        }
        if (($_POST['action'] ?? '') === 'confirm') {
            $count = $service->confirm((int)$_POST['import_id'], $actor);
            redirectWithNotification('?id=' . (int)$_POST['import_id'], $count . ' records imported.', 'success');
        }
        throw new DomainException('Invalid action.');
    } catch (Throwable $e) {
        redirectWithNotification('sacramental-import.php' . (!empty($_POST['import_id']) ? '?id=' . (int)$_POST['import_id'] : ''), $e->getMessage(), 'error');
    }
}

$batch = null;
if (!empty($_GET['id'])) {
    try {
        $batch = $service->batch((int)$_GET['id'], $actor);
    } catch (Throwable $e) {
        redirectWithNotification('sacramental-import.php', $e->getMessage(), 'error');
    }
}

$page_title = 'Sacramental CSV Import';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Sacramental Records' => 'manage-records.php',
    'CSV Import' => null
];

include '../templates/header.php';
?>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Validated Sacramental CSV Import';
    $page_header_subtitle = 'Upload UTF-8 CSV (maximum 2 MB / 5,000 rows). Nothing is inserted until validation passes and you confirm.';
    $page_header_icon = 'fa-file-csv';
    $show_back_button = true;
    $back_button_url = 'manage-records.php';
    include '../includes/page_header.php';
    ?>

            <div class="card card-body mb-4" style="border-radius: 10px; border: 1px solid #e5e7eb;">
                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="stage">
                    <div class="col-md-4">
                        <label class="form-label" style="font-weight: 600;">Record Type</label>
                        <select class="form-select" name="record_type">
                            <?php foreach (SacramentalRecordService::types() as $t): ?>
                                <option value="<?php echo e($t); ?>"><?php echo e(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-weight: 600;">CSV File</label>
                        <input class="form-control" type="file" name="csv" accept=".csv,text/csv" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary-gold w-100" style="height: 38px; font-weight: 600;">Validate</button>
                    </div>
                </form>
            </div>

            <?php if ($batch): ?>
                <div class="card" style="border-radius: 10px; border: 1px solid #e5e7eb;">
                    <div class="card-header d-flex justify-content-between align-items-center bg-light" style="font-weight: 600;">
                        <span><?php echo e($batch['original_name']); ?> &mdash; <?php echo e($batch['status']); ?></span>
                        <span class="badge bg-secondary"><?php echo (int)$batch['valid_rows']; ?> valid / <?php echo (int)$batch['invalid_rows']; ?> invalid</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Row</th>
                                    <th>Status</th>
                                    <th>Errors</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batch['rows'] as $r): ?>
                                    <tr class="<?php echo $r['validation_status'] === 'valid' ? '' : 'table-danger'; ?>">
                                        <td><?php echo (int)$r['row_number']; ?></td>
                                        <td><span class="badge <?php echo $r['validation_status'] === 'valid' ? 'bg-success' : 'bg-danger'; ?>"><?php echo e($r['validation_status']); ?></span></td>
                                        <td><?php echo e(implode('; ', json_decode($r['errors_json'], true) ?: [])); ?></td>
                                        <td><code><?php echo e($r['row_data']); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body d-flex justify-content-between align-items-center border-top">
                        <a class="btn btn-outline-secondary btn-sm" href="sacramental-import-errors.php?id=<?php echo (int)$batch['import_id']; ?>">
                            <i class="fas fa-download"></i> Download Error Report
                        </a>
                        <?php if ($batch['status'] === 'preview' && (int)$batch['invalid_rows'] === 0): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Import all validated rows into the official registry?');">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="confirm">
                                <input type="hidden" name="import_id" value="<?php echo (int)$batch['import_id']; ?>">
                                <button class="btn btn-success btn-sm"><i class="fas fa-check"></i> Confirm Import</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

</div>
<?php include '../templates/footer.php'; ?>
