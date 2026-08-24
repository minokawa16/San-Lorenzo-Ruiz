<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
if (!isLoggedIn() || !hasPermission('admin.access')) {
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

require_once __DIR__ . '/IDOCRProcessor.php';

$wrapperClass = '\\thiagoalessio\\TesseractOCR\\TesseractOCR';
$tesseractClassExists = class_exists($wrapperClass);
$tesseractBinary = IDOCRProcessor::findTesseractBinary();
$tesseractVersion = null;
$tesseractLanguages = [];

if ($tesseractClassExists && $tesseractBinary) {
    try {
        $ocr = new $wrapperClass();
        $ocr->executable($tesseractBinary);
        $tesseractVersion = trim((string)$ocr->version());
        $tesseractLanguages = $ocr->availableLanguages();
    } catch (Throwable $e) {
        error_log('OCR health version check failed: ' . $e->getMessage());
    }
}

$tmpDir = dirname(__DIR__) . '/storage/tmp_ids';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}
$tmpDirWritable = is_dir($tmpDir) && is_writable($tmpDir);
$cloudConfigured = !empty(getenv('OCR_SPACE_API_KEY'));
$ocrAvailable = ($tesseractClassExists && $tesseractBinary !== null) || $cloudConfigured;

$checks = [
    'php_version' => PHP_VERSION,
    'autoload_loaded' => $autoloadLoaded,
    'composer_package' => $tesseractClassExists,
    'tesseract_binary' => $tesseractBinary,
    'tesseract_version' => $tesseractVersion,
    'tesseract_languages' => $tesseractLanguages,
    'cloud_ocr_configured' => $cloudConfigured,
    'ocr_available' => $ocrAvailable,
    'gd_loaded' => extension_loaded('gd'),
    'imagick_loaded' => extension_loaded('imagick'),
    'curl_loaded' => extension_loaded('curl'),
    'temp_dir_writable' => $tmpDirWritable,
    'smoke_test' => null,
];

if (isset($_GET['smoke']) && $_GET['smoke'] === '1') {
    $checks['smoke_test'] = false;

    if (!$ocrAvailable || !$tmpDirWritable) {
        $checks['smoke_error'] = 'Neither local Tesseract binary nor OCR_SPACE_API_KEY is available, or temp directory is not writable.';
    } else {
        $imagePath = $tmpDir . '/' . uniqid('ocr_health_', true) . '.png';
        $image = imagecreatetruecolor(900, 220);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 900, 220, $white);
        imagestring($image, 5, 40, 80, 'TUGON OCR TEST 12345', $black);
        imagepng($image, $imagePath);
        imagedestroy($image);

        try {
            $processor = new IDOCRProcessor($tmpDir, 65);
            $scanResult = $processor->scanID($imagePath);
            $text = $scanResult['raw_text'] ?? '';
            $checks['smoke_text'] = $text;
            $checks['smoke_test'] = stripos($text, 'TUGON') !== false || strpos($text, '12345') !== false || !empty($text);
        } catch (Throwable $e) {
            error_log('OCR health smoke check failed: ' . $e->getMessage());
            $checks['smoke_error'] = 'OCR smoke check failed: ' . $e->getMessage();
        } finally {
            @unlink($imagePath);
        }
    }
}

$ok = $checks['autoload_loaded'] && $checks['ocr_available'] && $checks['temp_dir_writable'];
if (isset($_GET['smoke']) && $_GET['smoke'] === '1') {
    $ok = $ok && $checks['smoke_test'] === true;
}

http_response_code($ok ? 200 : 503);
echo json_encode([
    'status' => $ok ? 'ok' : 'unavailable',
    'ocr_available' => $ocrAvailable,
    'engine' => ($tesseractBinary ? 'tesseract_local' : ($cloudConfigured ? 'ocr_space_cloud' : 'none')),
    'ocr' => $checks,
]);
