<?php
$id = intval($_POST['request_id'] ?? $_GET['id'] ?? 0);
header('Location: request-workflow.php?id=' . $id);
exit;
