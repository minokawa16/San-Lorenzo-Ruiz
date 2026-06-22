<?php
/**
 * Reusable Back Button Component
 * 
 * Usage:
 * <?php include '../includes/back_button.php'; ?>
 * 
 * Or with custom button text:
 * <?php $back_button_label = 'Back'; include '../includes/back_button.php'; ?>
 */

// Pages can override this before including the component.
if (!isset($back_button_label)) {
    $back_button_label = 'Go Back';
}
?>

<div class="mb-4">
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" onclick="history.back()" class="btn btn-dashboard" title="Go back to previous page">
            <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($back_button_label); ?>
        </button>
    </div>
</div>
