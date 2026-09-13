<?php
/**
 * Avatar Streaming Module - Securely serves user avatars with multi-root resolution,
 * HTTP caching (ETag/Last-Modified), and graceful SVG initials fallback.
 */
define('ALLOW_EMBEDDED_FRAMES', true);
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/database/config.php';
require_once __DIR__ . '/includes/helpers.php';

$user_id = intval($_GET['id'] ?? $_GET['u'] ?? 0);
if ($user_id <= 0 && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $qs_params);
    $user_id = intval($qs_params['id'] ?? $qs_params['u'] ?? 0);
}
if ($user_id <= 0 && !empty($_SERVER['argv'][1])) {
    parse_str($_SERVER['argv'][1], $arg_params);
    $user_id = intval($arg_params['id'] ?? $arg_params['u'] ?? $_SERVER['argv'][1]);
}
if ($user_id <= 0 && isset($_SESSION['user_id'])) {
    $user_id = intval($_SESSION['user_id']);
}

if ($user_id <= 0) {
    serveAvatarFallback('?', 'Parishioner');
}

$stmt = $conn->prepare("SELECT id, fullname, first_name, profile_picture FROM users WHERE id = ? LIMIT 1");
if (!$stmt) {
    serveAvatarFallback('?', 'User');
}
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data) {
    serveAvatarFallback('?', 'User');
}

$raw_path = (string)($user_data['profile_picture'] ?? '');
if ($raw_path === '') {
    $initial = strtoupper(substr(trim((string)($user_data['first_name'] ?: $user_data['fullname'] ?: 'U')), 0, 1));
    serveAvatarFallback($initial, $user_data['fullname'] ?: 'User');
}

$clean_rel = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $raw_path), DIRECTORY_SEPARATOR);
$basename = basename($clean_rel);

$candidate_roots = array_filter(array_unique([
    __DIR__,
    dirname(__DIR__),
    rtrim((string)(getenv('TUGON_DATA_DIR') ?: ''), '/\\'),
    rtrim((string)(getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: ''), '/\\'),
    '/var/www/tugon-data',
    '/var/www/html',
    sys_get_temp_dir(),
]));

$resolved_file = null;
if (is_file($raw_path)) {
    $resolved_file = realpath($raw_path);
}

if (!$resolved_file) {
    foreach ($candidate_roots as $root) {
        if (!is_dir($root)) continue;
        $attempts = [
            $root . DIRECTORY_SEPARATOR . $clean_rel,
            $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $basename,
            $root . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $basename,
            $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $clean_rel,
            $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $basename,
        ];
        foreach ($attempts as $test) {
            if (is_file($test)) {
                $resolved_file = realpath($test);
                break 2;
            }
        }
    }
}

if (!$resolved_file || !is_readable($resolved_file)) {
    $initial = strtoupper(substr(trim((string)($user_data['first_name'] ?: $user_data['fullname'] ?: 'U')), 0, 1));
    serveAvatarFallback($initial, $user_data['fullname'] ?: 'User');
}

$ext = strtolower(pathinfo($resolved_file, PATHINFO_EXTENSION));
$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];
$mime = $mime_map[$ext] ?? 'image/jpeg';
if (function_exists('finfo_open')) {
    $f = finfo_open(FILEINFO_MIME_TYPE);
    $detected = finfo_file($f, $resolved_file);
    finfo_close($f);
    if ($detected && str_starts_with($detected, 'image/')) {
        $mime = $detected;
    }
}

while (ob_get_level() > 0) {
    @ob_end_clean();
}

$mtime = filemtime($resolved_file);
$etag = '"' . md5($resolved_file . $mtime . filesize($resolved_file)) . '"';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($resolved_file));
header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Content-Type-Options: nosniff');

if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
    (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)) {
    http_response_code(304);
    exit;
}

$fp = fopen($resolved_file, 'rb');
if ($fp) {
    while (!feof($fp)) {
        echo fread($fp, 65536);
        flush();
    }
    fclose($fp);
} else {
    readfile($resolved_file);
}
exit;

/**
 * Output an inline SVG avatar with the user's initial letter
 */
function serveAvatarFallback(string $initial, string $name = ''): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $clean_initial = htmlspecialchars($initial !== '' ? $initial : 'U', ENT_QUOTES, 'UTF-8');
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
  <defs>
    <linearGradient id="avGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#2E3A2D" />
      <stop offset="100%" stop-color="#1d251d" />
    </linearGradient>
  </defs>
  <circle cx="50" cy="50" r="50" fill="url(#avGrad)" />
  <circle cx="50" cy="50" r="47" fill="none" stroke="#c89b3c" stroke-width="2.5" opacity="0.6" />
  <text x="50" y="62" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="42" font-weight="700" fill="#c89b3c" text-anchor="middle">{$clean_initial}</text>
</svg>
SVG;

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $svg;
    exit;
}
