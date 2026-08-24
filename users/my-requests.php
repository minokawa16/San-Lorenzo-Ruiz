<?php
/**
 * My Requests entry point.
 *
 * HTTP/session concerns remain here; query and presentation logic live in the
 * controller, service, repository, and view layers.
 */
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/database/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/controllers/MyRequestsController.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$repository = new RequestRepository($conn);
$service = new RequestListService($repository);
$controller = new MyRequestsController($service);
$viewModel = $controller->index((int) $_SESSION['user_id'], $_GET);
extract($viewModel, EXTR_SKIP);

require dirname(__DIR__) . '/views/users/my-requests.php';
