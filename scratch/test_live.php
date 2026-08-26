<?php
$h = file_get_contents('https://tugon-web-production.up.railway.app/auth/login.php');
echo "Length: " . strlen($h) . "\n";
preg_match_all('/<input[^>]+>/i', $h, $inputs);
print_r($inputs[0]);
