<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}require_once __DIR__.'/config.php';require_once dirname(__DIR__).'/includes/helpers.php';require_once dirname(__DIR__).'/services/AnnouncementService.php';$result=(new AnnouncementService($conn))->tick(0);echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
