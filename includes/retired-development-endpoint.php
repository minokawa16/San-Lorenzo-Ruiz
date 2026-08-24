<?php
/** Shared response for obsolete setup, demo, and diagnostic browser routes. */
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "This development-only endpoint has been retired. Use the documented CLI tools and automated checks.\n";
exit;
