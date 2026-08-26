<?php
$files = [
    'assets/css/auth-mobile.css',
    'assets/css/parish-design-system.css',
    'assets/css/style.css',
    'assets/css/login-institutional.css',
];

foreach ($files as $f) {
    $path = __DIR__ . '/../' . $f;
    $lines = file($path);
    $depth = 0;
    $errors = [];
    foreach ($lines as $i => $line) {
        $open = substr_count($line, '{');
        $close = substr_count($line, '}');
        $depth += $open - $close;
        if ($depth < 0) {
            $errors[] = "Negative depth at line " . ($i + 1);
            $depth = 0;
        }
    }
    echo "File: $f | Final Depth: $depth | Errors: " . count($errors) . "\n";
}
