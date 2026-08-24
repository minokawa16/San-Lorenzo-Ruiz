<?php
/**
 * Certificate Management Redirect - Sends administrators to the active certificate generator workflow.
 */
session_start();
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('certificates.manage');

// Redirect to certificate generator
header("Location: certificate-workflow.php");
exit;
?>
