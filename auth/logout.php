<?php
/** Destroy the centralized TUGON session and redirect to login. */
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';

$logoutUserId=(int)($_SESSION['user_id']??0);
if($logoutUserId>0){createAuditLog($conn,$logoutUserId,'LOGOUT','users',$logoutUserId);}
logoutUser();
?>

