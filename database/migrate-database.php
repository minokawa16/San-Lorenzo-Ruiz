<?php
/** Retired ad-hoc migration endpoint. */
if (PHP_SAPI === 'cli') {
    require __DIR__ . '/migrate.php';
    return;
}

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit("This migration endpoint has been retired. Run: php database/migrate.php status\n");

