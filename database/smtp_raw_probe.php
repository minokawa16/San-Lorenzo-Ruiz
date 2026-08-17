<?php
header('Content-Type: text/plain; charset=UTF-8');

$targets = [
    ['tcp://smtp.gmail.com:587', 'Gmail STARTTLS port'],
    ['ssl://smtp.gmail.com:465', 'Gmail SSL port'],
];

foreach ($targets as [$target, $label]) {
    echo $label . "\n";
    echo "Target: " . $target . "\n";

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($target, $errno, $errstr, 20);

    if (!$socket) {
        echo "Connect: FAIL " . $errno . ' ' . ($errstr ?: 'unknown error') . "\n\n";
        continue;
    }

    stream_set_timeout($socket, 20);
    $line = fgets($socket, 515);
    $meta = stream_get_meta_data($socket);
    fclose($socket);

    echo "Connect: OK\n";
    echo "Greeting: " . (($line !== false && $line !== '') ? trim($line) : '(empty)') . "\n";
    echo "Timed out: " . (!empty($meta['timed_out']) ? 'yes' : 'no') . "\n\n";
}
