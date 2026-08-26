<?php
require 'database/config.php';
$res = $conn->query('SELECT filename, checksum FROM schema_migrations');
while ($r = $res->fetch_assoc()) {
    $file = __DIR__ . '/../database/canonical-migrations/' . $r['filename'];
    $raw = is_file($file) ? hash_file('sha256', $file) : 'missing';
    $norm = is_file($file) ? hash('sha256', str_replace("\r\n", "\n", file_get_contents($file))) : 'missing';
    echo sprintf("%-55s | DB: %s\n  RAW: %s\n  NORM: %s\n", $r['filename'], $r['checksum'], $raw, $norm);
}
