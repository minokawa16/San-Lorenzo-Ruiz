<?php
/**
 * Certificate Layout Management - one editable layout per certificate type.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
include '../includes/CertificateTemplateManager.php';

requireAdmin();
requirePermission('certificates.manage');
ensureCertificateTemplateSchema($conn);

$page_title = 'Certificate Layouts';
$types = certificateTemplateTypes();
$notifications = consumeActionNotifications();
$layouts = [];
foreach ($types as $type => $label) {
    $layout = getCertificateLayout($conn, $type);
    $layouts[$type] = $layout;
}
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="mb-1"><i class="fas fa-pen-ruler"></i> Certificate Layouts</h1>
            <p class="text-muted mb-0">Edit each certificate layout directly. One saved layout is maintained per certificate type.</p>
        </div>
    </div>

    <?php foreach ($notifications as $notice): ?>
        <div class="alert alert-<?php echo $notice['type'] === 'error' ? 'danger' : e($notice['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo e($notice['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row g-3">
        <?php foreach ($types as $type => $label): ?>
            <?php $settings = $layouts[$type]['settings']; ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between gap-2 align-items-start mb-3">
                            <div>
                                <h5 class="mb-1"><?php echo e($label); ?></h5>
                                <div class="text-muted small">Last saved: <?php echo !empty($layouts[$type]['updated_at']) ? e(date('M d, Y h:i A', strtotime($layouts[$type]['updated_at']))) : 'Original defaults'; ?></div>
                            </div>
                            <span class="badge bg-<?php echo !empty($layouts[$type]['layout_id']) ? 'success' : 'secondary'; ?>">
                                <?php echo !empty($layouts[$type]['layout_id']) ? 'Custom' : 'Original'; ?>
                            </span>
                        </div>
                        <div class="small mb-3">
                            <strong><?php echo e($settings['static_text']['certificate_title']); ?></strong>
                            <div class="text-muted"><?php echo e($settings['static_text']['parish_name']); ?></div>
                            <div class="text-muted"><?php echo e($settings['static_text']['parish_address']); ?></div>
                        </div>
                        <a class="btn btn-primary w-100" href="certificate-layout-editor.php?type=<?php echo urlencode($type); ?>">
                            <i class="fas fa-pen-to-square"></i> Edit Certificate Layout
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
