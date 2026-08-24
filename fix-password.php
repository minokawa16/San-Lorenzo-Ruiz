<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit("This password repair route has been retired. Use the normal password recovery flow.\n");

