<?php
/**
 * Standardized Section Sub-Header Component
 * 
 * Note: The primary page title, subtitle, icon, search bar, and admin profile
 * are already rendered by templates/header.php in the top navigation bar.
 * This component now only provides the back link navigation if requested.
 */

$show_back = !empty($show_back_button) && $show_back_button === true;
$back_url = $back_button_url ?? '';
?>
<?php if ($show_back): ?>
<div class="parish-section-header-component parish-back-button-wrap no-print" style="margin-bottom: 14px;">
    <?php if (!empty($back_url)): ?>
        <a href="<?php echo e($back_url); ?>" class="parish-back-link" title="Go back to previous page">
            <i class="fas fa-arrow-left"></i> Go Back
        </a>
    <?php else: ?>
        <button type="button" onclick="history.back()" class="parish-back-link" title="Go back to previous page">
            <i class="fas fa-arrow-left"></i> Go Back
        </button>
    <?php endif; ?>
</div>
<?php endif; ?>
