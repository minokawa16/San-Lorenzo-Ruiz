<?php
$id = intval($_GET['id'] ?? 0);
header('Location: request-workflow.php?id=' . $id);
exit;
