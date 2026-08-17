<?php
/**
 * LOGOUT - Destroy session and redirect to login
 * Simple, direct logout with no complications
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include auth functions
include '../includes/helpers.php';
include '../includes/auth.php';

// Use auth function to logout
logoutUser();
?>

