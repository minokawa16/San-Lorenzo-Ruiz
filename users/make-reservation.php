<?php
/**
 * Reservation Redirect Module - Routes parishioners to the service reservation workflow.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

redirect('request-service.php');
?>
