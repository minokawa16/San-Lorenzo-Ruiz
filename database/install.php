<?php
/** Retired web installer. Database setup is CLI-only. */
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit("The web database installer has been retired. Run: php database/migrate.php up\n");

