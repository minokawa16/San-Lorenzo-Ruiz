<?php
/** Retired legacy requirement-file endpoint. */
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "This legacy file endpoint has been retired. Use request-document.php.\n";
