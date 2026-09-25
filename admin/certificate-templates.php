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

$current_user_id = intval($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $cert_type = normalizeCertificateTemplateType($_POST['certificate_type'] ?? '');
    $types = certificateTemplateTypes();

    if ($action === 'reset_layout' && isset($types[$cert_type])) {
        if (resetCertificateLayoutToDefault($conn, $cert_type, $current_user_id)) {
            createAuditLog($conn, $current_user_id, 'RESET_CERTIFICATE_LAYOUT', 'certificate_layouts', 0, null, ['certificate_type' => $cert_type]);
            queueActionNotification(certificateTemplateTypeLabel($cert_type) . ' layout reset to original defaults successfully.');
        } else {
            queueActionNotification('Failed to reset layout.', 'error');
        }
        header('Location: certificate-templates.php');
        exit;
    }
}

$page_title = 'Certificate Layouts';
$types = certificateTemplateTypes();
$notifications = consumeActionNotifications();
$layouts = [];
foreach ($types as $type => $label) {
    $layout = getCertificateLayout($conn, $type);
    $layouts[$type] = $layout;
}
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Certificate Generator' => 'certificate-generator.php',
    'Certificate Layouts' => null
];
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Certificate Layouts';
    $page_header_subtitle = 'Edit and manage official certificate layout templates, typography, and live issuance settings per sacrament.';
    $page_header_icon = 'fa-pen-ruler';
    $show_back_button = true;
    $back_button_url = 'certificate-generator.php';
    include '../includes/page_header.php';
    ?>

    <?php foreach ($notifications as $notice): ?>
        <div class="alert alert-<?php echo $notice['type'] === 'error' ? 'danger' : e($notice['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo e($notice['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row g-3">
        <?php foreach ($types as $type => $label): ?>
            <?php 
            $layout_data = $layouts[$type];
            $settings = $layout_data['settings']; 
            $is_custom = isCustomCertificateLayout($layout_data, $type);
            $last_saved_text = ($is_custom && !empty($layout_data['updated_at'])) 
                ? 'Last saved: ' . date('M d, Y h:i A', strtotime($layout_data['updated_at'])) 
                : 'Last saved: Original defaults';
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between gap-2 align-items-start mb-3">
                            <div>
                                <h5 class="mb-1"><?php echo e($label); ?></h5>
                                <div class="text-muted small"><?php echo e($last_saved_text); ?></div>
                            </div>
                            <span class="badge bg-<?php echo $is_custom ? 'success' : 'secondary'; ?>">
                                <?php echo $is_custom ? 'Custom' : 'Original'; ?>
                            </span>
                        </div>
                        <div class="small mb-3 flex-grow-1">
                            <strong class="d-block mb-1 text-primary"><?php echo e($settings['static_text']['certificate_title'] ?? ''); ?></strong>
                            <div class="text-muted"><?php echo e($settings['static_text']['parish_name'] ?? ''); ?></div>
                            <div class="text-muted"><?php echo e($settings['static_text']['parish_address'] ?? ''); ?></div>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-primary flex-grow-1" href="certificate-layout-editor.php?type=<?php echo urlencode($type); ?>">
                                <i class="fas fa-pen-to-square"></i> Edit Layout
                            </a>
                            <?php if ($is_custom): ?>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to reset this <?php echo e($label); ?> layout to original defaults? This will immediately affect all newly generated or issued certificates.');" class="d-inline">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="reset_layout">
                                    <input type="hidden" name="certificate_type" value="<?php echo e($type); ?>">
                                    <button type="submit" class="btn btn-outline-secondary" title="Reset to Original Defaults" aria-label="Reset <?php echo e($label); ?> to Original Defaults">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
