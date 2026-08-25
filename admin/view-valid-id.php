<?php
/**
 * Valid ID Review & Document Viewer Module
 * Securely streams submitted identity documents and live face captures
 * with robust multi-root path resolution, decryption, and graceful fallbacks.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAdmin();
requirePermission('registrations.verify');
ensureUserVerificationSchema($conn);

$user_id = intval($_GET['id'] ?? 0);
$requested_type = strtolower(trim($_GET['type'] ?? 'id'));

if (!in_array($requested_type, ['id', 'front', 'back', 'face'], true)) {
    $requested_type = 'id';
}

if ($user_id <= 0) {
    serveDocumentPlaceholder($requested_type, 'Document not found');
}

$stmt = $conn->prepare("SELECT id, fullname, valid_id_path, valid_id_mime_type, valid_id_back_path, valid_id_back_mime_type, face_image_path, face_image_mime_type FROM users WHERE id = ? LIMIT 1");
if (!$stmt) {
    serveDocumentPlaceholder($requested_type, 'Unable to query document');
}

$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    serveDocumentPlaceholder($requested_type, 'User record not found');
}

if ($requested_type === 'face') {
    $raw_path = $user['face_image_path'] ?? '';
    $mime_type = $user['face_image_mime_type'] ?? 'image/jpeg';
} elseif ($requested_type === 'back') {
    $raw_path = $user['valid_id_back_path'] ?? '';
    $mime_type = $user['valid_id_back_mime_type'] ?? 'image/jpeg';
} else {
    $raw_path = $user['valid_id_path'] ?? '';
    $mime_type = $user['valid_id_mime_type'] ?? 'image/jpeg';
}

if (empty($raw_path)) {
    serveDocumentPlaceholder($requested_type, 'No document submitted');
}

// 1. Check if the field is a direct base64 data URI
if (strpos($raw_path, 'data:image/') === 0) {
    if (preg_match('/^data:image\/(jpeg|png|webp|gif);base64,(.+)$/is', $raw_path, $m)) {
        $mime_type = 'image/' . $m[1];
        $contents = base64_decode($m[2]);
        if ($contents !== false && $contents !== '') {
            serveBinaryImage($contents, $mime_type);
        }
    }
}

// 2. Multi-root candidate search for stored file
$clean_rel = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $raw_path), DIRECTORY_SEPARATOR);
$candidate_roots = array_filter([
    dirname(__DIR__),
    rtrim((string) (getenv('TUGON_DATA_DIR') ?: ''), '/\\'),
    rtrim((string) (getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: ''), '/\\'),
    sys_get_temp_dir(),
]);

$resolved_file = null;

// Direct check if raw_path is already absolute
if (is_file($raw_path)) {
    $resolved_file = $raw_path;
}

if (!$resolved_file) {
    foreach ($candidate_roots as $root) {
        $test_path = $root . DIRECTORY_SEPARATOR . $clean_rel;
        if (is_file($test_path)) {
            $resolved_file = $test_path;
            break;
        }

        // Also check inside uploads subfolder directly
        $subfolder = $requested_type === 'face' ? 'live_faces' : 'valid_ids';
        $test_sub = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subfolder . DIRECTORY_SEPARATOR . basename($clean_rel);
        if (is_file($test_sub)) {
            $resolved_file = $test_sub;
            break;
        }
    }
}

if (!$resolved_file || !is_readable($resolved_file)) {
    serveDocumentPlaceholder($requested_type, 'Document storage unavailable');
}

// 3. Decrypt if encrypted (.enc), otherwise read directly
$is_encrypted = substr($resolved_file, -4) === '.enc';
$contents = $is_encrypted ? decryptStoredFile($resolved_file) : file_get_contents($resolved_file);

if ($contents === false || $contents === '') {
    // Fallback: test raw decrypt in case decryptStoredFile returned empty
    $raw_content = file_get_contents($resolved_file);
    if ($raw_content !== false && $raw_content !== '') {
        $decrypted_fallback = function_exists('decryptSensitiveValue') ? decryptSensitiveValue($raw_content) : false;
        $contents = ($decrypted_fallback !== false && $decrypted_fallback !== '') ? $decrypted_fallback : $raw_content;
    }
}

if ($contents === false || $contents === '') {
    serveDocumentPlaceholder($requested_type, 'Unable to decrypt document');
}

// 4. Determine MIME type
if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    $img_info = @getimagesizefromstring($contents);
    if ($img_info && !empty($img_info['mime'])) {
        $mime_type = $img_info['mime'];
    } else {
        $ext = strtolower(pathinfo(str_replace('.enc', '', $resolved_file), PATHINFO_EXTENSION));
        $mime_type = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : ($ext === 'png' ? 'image/png' : 'application/octet-stream');
    }
}

serveBinaryImage($contents, $mime_type);

/**
 * Output binary image with security and caching headers
 */
function serveBinaryImage($contents, $mime_type) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . strlen($contents));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $contents;
    exit;
}

/**
 * Generate a graceful inline SVG placeholder when an image cannot be read from disk
 */
function serveDocumentPlaceholder($type, $label = 'Document Not Found') {
    if (ob_get_level()) {
        ob_end_clean();
    }

    $title = match ($type) {
        'face' => 'Live Face Capture',
        'back' => 'Valid ID (Back)',
        default => 'Valid ID (Front)',
    };

    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 240" width="400" height="240">
  <defs>
    <linearGradient id="pGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#FBF9F4" />
      <stop offset="100%" stop-color="#EFECE4" />
    </linearGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#pGrad)" rx="10" />
  <rect x="8" y="8" width="384" height="224" fill="none" stroke="#D8CEB8" stroke-width="2" stroke-dasharray="6,6" rx="8" />
  <g transform="translate(200, 95)" text-anchor="middle">
    <circle cx="0" cy="-10" r="30" fill="#E4DCB2" opacity="0.6" />
    <path d="M-12,-10 L12,-10 M-12,-18 L12,-18 M-12,-2 L4,-2" stroke="#8C6F32" stroke-width="2.5" stroke-linecap="round" />
    <rect x="-18" y="-24" width="36" height="30" rx="4" fill="none" stroke="#8C6F32" stroke-width="2.5" />
    <text x="0" y="44" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="14" font-weight="700" fill="#4A4438">{$title}</text>
    <text x="0" y="64" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="11.5" font-weight="500" fill="#8A8272">{$label}</text>
  </g>
</svg>
SVG;

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $svg;
    exit;
}
