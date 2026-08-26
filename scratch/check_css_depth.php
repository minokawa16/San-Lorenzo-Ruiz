<?php
$lines = file(__DIR__ . '/../assets/css/auth-mobile.css');
$depth = 0;
foreach ($lines as $i => $line) {
    $open = substr_count($line, '{');
    $close = substr_count($line, '}');
    $depth += $open - $close;
    if ($depth === 0 && $i > 10 && $i < count($lines) - 2) {
        echo "Closed early at line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
echo "Final depth: $depth\n";
