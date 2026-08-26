<?php
/**
 * Standardized Section Sub-Header Component
 * 
 * Usage in any admin page:
 * <?php 
 * $page_header_title = 'Manage Parishioners'; // Optional, defaults to current page title
 * $page_header_subtitle = 'Review parishioner accounts, verification status, and personal registry entries.'; // Optional
 * $page_header_icon = 'fa-people-roof'; // Optional, defaults to $header_icon
 * $show_back_button = true; // Optional, default true
 * $back_button_url = 'dashboard.php'; // Optional, default history.back()
 * include '../includes/page_header.php'; 
 * ?>
 */

$sec_title = $page_header_title ?? ($admin_header_title ?? (isset($page_title) ? preg_replace('/\s*\|\s*.*$/', '', $page_title) : 'Page'));
$sec_subtitle = $page_header_subtitle ?? ($admin_header_description ?? '');
$sec_icon = $page_header_icon ?? ($header_icon ?? 'fa-table-cells-large');
$show_back = $show_back_button ?? true;
$back_url = $back_button_url ?? '';
?>
<section class="parish-section-header-component no-print">
    <?php if ($show_back): ?>
        <?php if (!empty($back_url)): ?>
            <a href="<?php echo e($back_url); ?>" class="parish-back-link" title="Go back to previous page">
                <i class="fas fa-arrow-left"></i> Go Back
            </a>
        <?php else: ?>
            <button type="button" onclick="history.back()" class="parish-back-link" title="Go back to previous page">
                <i class="fas fa-arrow-left"></i> Go Back
            </button>
        <?php endif; ?>
    <?php endif; ?>

    <div class="parish-section-title-wrap">
        <h2 class="parish-section-title">
            <span class="parish-section-icon-badge">
                <i class="fas <?php echo e($sec_icon); ?>"></i>
            </span>
            <?php echo e($sec_title); ?>
        </h2>
        <div class="parish-gold-underline"></div>
        <?php if (!empty($sec_subtitle)): ?>
            <p class="parish-section-subtitle"><?php echo e($sec_subtitle); ?></p>
        <?php endif; ?>
    </div>
</section>
