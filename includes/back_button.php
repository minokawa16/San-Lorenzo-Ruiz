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

if (!isset($back_button_label)) {
    $back_button_label = 'Go Back';
}
?>

<div class="parish-back-button-wrap mb-3 no-print">
    <button type="button" onclick="history.back()" class="parish-back-link" title="Go back to previous page">
        <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($back_button_label); ?>
    </button>
</div>
