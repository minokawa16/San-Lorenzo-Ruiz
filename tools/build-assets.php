<?php
declare(strict_types=1);

/** Build the shared CSS artifact while retaining readable development sources. */
$root = dirname(__DIR__);
$sources = [
    'assets/css/style.css',
    'assets/css/premium-parish.css',
    'assets/css/parish-design-system.css',
    'assets/css/responsive-unified.css',
    'assets/css/theme.css',
    'assets/css/mobile-design-system.css',
];

$parts = [];
foreach ($sources as $source) {
    $path = $root . '/' . $source;
    if (!is_file($path)) {
        throw new RuntimeException("Missing asset source: {$source}");
    }
    $parts[] = "/*! source: {$source} */\n" . file_get_contents($path);
}

$css = implode("\n", $parts);
// Conservative minification preserves value-level spacing in calc(), URLs,
// and quoted strings while removing comments and blank/indented lines.
$css = preg_replace('~/\*(?!\!)[\s\S]*?\*/~', '', $css) ?? $css;
$lines = preg_split('/\R/', $css) ?: [];
$css = implode("\n", array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''))) . "\n";

$output = $root . '/assets/css/tugon-core.bundle.min.css';
if (file_put_contents($output, $css, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write the CSS bundle.');
}
echo sprintf("Built %s (%d bytes from %d sources).\n", basename($output), filesize($output), count($sources));
