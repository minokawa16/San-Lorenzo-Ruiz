<?php
/**
 * Legacy migration entry point.
 *
 * Database migrations are intentionally CLI-only. Keeping this file as a
 * guarded compatibility entry point prevents old bookmarks from executing
 * schema changes through a browser.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    exit("The web migration runner has been retired. Run: php database/migrate.php status\n");
}

require __DIR__ . '/migrate.php';

