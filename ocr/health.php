<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
if (!isLoggedIn() || !hasPermission('admin.access') || (isAdmin() && empty($_SESSION['mfa_verified']))) {
    http_response_code(401);
    echo json_encode(['status' => 'unauthorized']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

$autoloadPaths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

$checks = [
    'php' => PHP_VERSION,
    'autoload' => $autoloadLoaded,
    'curl' => extension_loaded('curl'),
    'gd' => extension_loaded('gd'),
    'imagick' => extension_loaded('imagick'),
    'ocr_space_configured' => !empty(getenv('OCR_SPACE_API_KEY')),
    'smoke_test' => null,
];

if (isset($_GET['smoke']) && $_GET['smoke'] === '1') {
    $checks['smoke_test'] = false;

    if (!$checks['ocr_space_configured'] || !$checks['curl']) {
        $checks['smoke_error'] = 'OCR_SPACE_API_KEY environment variable or cURL extension is unavailable.';
    } else {
        $tmpDir = dirname(__DIR__) . '/storage/tmp_ids';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $imagePath = $tmpDir . '/' . uniqid('ocr_health_', true) . '.png';
        $image = imagecreatetruecolor(900, 220);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 900, 220, $white);
        imagestring($image, 5, 40, 80, 'TUGON OCR TEST 12345', $black);
        imagepng($image, $imagePath);
        imagedestroy($image);

        try {
            $base64Image = 'data:image/png;base64,' . base64_encode(file_get_contents($imagePath));
            require_once __DIR__ . '/api_process_id.php';
            $text = runCloudOcr($base64Image);
            $checks['smoke_text'] = $text;
            $checks['smoke_test'] = stripos($text, 'TUGON') !== false || strpos($text, '12345') !== false;
        } catch (Throwable $e) {
            error_log('OCR health smoke check failed: ' . $e->getMessage());
            $checks['smoke_error'] = 'OCR smoke check failed: ' . $e->getMessage();
        } finally {
            @unlink($imagePath);
        }
    }
}

$ok = $checks['autoload'] && $checks['curl'] && $checks['ocr_space_configured'];
if (isset($_GET['smoke']) && $_GET['smoke'] === '1') {
    $ok = $ok && $checks['smoke_test'] === true;
}

http_response_code($ok ? 200 : 503);
echo json_encode([
    'status' => $ok ? 'ok' : 'unavailable',
    'ocr' => $checks,
]);
