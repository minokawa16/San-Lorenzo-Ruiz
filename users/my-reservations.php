<?php
/**
 * My Reservations Redirect Module - Routes parishioners to their reservation records.
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
