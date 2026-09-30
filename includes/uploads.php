<?php

if (!defined('TUGON_UPLOAD_MAX_BYTES')) {
    define('TUGON_UPLOAD_MAX_BYTES', 64 * 1024 * 1024); // 64 MB maximum per file
}

if (!defined('TUGON_ALLOWED_EXTENSIONS')) {
    define('TUGON_ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'webp']);
}

if (!defined('TUGON_ALLOWED_MIME_TYPES')) {
    define('TUGON_ALLOWED_MIME_TYPES', [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp'
    ]);
}

/** Canonical validation policy for request-document uploads. */
function getRequestDocumentConfig(): array {
    return [
        'max_size' => TUGON_UPLOAD_MAX_BYTES,
        'extensions' => TUGON_ALLOWED_EXTENSIONS,
        'mime_types' => TUGON_ALLOWED_MIME_TYPES,
        'error_message' => 'Only PDF or image files (JPG, PNG, WEBP) are allowed. Please convert your document and upload again.'
    ];
}

function isRequestImageDocument($mime_type, $filename = ''): bool {
    $clean_mime = strtolower(trim((string) $mime_type));
    if ($clean_mime !== '' && str_starts_with($clean_mime, 'image/')) {
        return true;
    }
    if ($filename !== '') {
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'], true);
    }
    return in_array($clean_mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/svg+xml'], true);
}

/**
 * Strict authoritative validation of a single uploaded file.
 * Checks extension allow-list, size limit (64 MB), finfo MIME type, and magic bytes.
 *
 * @param array $file Single file entry from $_FILES (containing tmp_name, name, size, error)
 * @return array ['ok' => bool, 'has_file' => bool, 'error' => string, 'mime' => string, 'extension' => string]
 */
function validateUploadedDocument(array $file): array {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        return ['ok' => true, 'has_file' => false];
    }

    $invalidMsg = 'Only PDF or image files (JPG, PNG, WEBP) are allowed. Please convert your document and upload again.';

    if ($error !== UPLOAD_ERR_OK || (!is_uploaded_file($file['tmp_name']) && php_sapi_name() !== 'cli')) {
        return ['ok' => false, 'has_file' => true, 'error' => $invalidMsg];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > TUGON_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'has_file' => true, 'error' => $invalidMsg];
    }

    $origName = basename((string) ($file['name'] ?? ''));
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, TUGON_ALLOWED_EXTENSIONS, true)) {
        return ['ok' => false, 'has_file' => true, 'error' => $invalidMsg];
    }

    $tmpPath = (string) $file['tmp_name'];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : (string) mime_content_type($tmpPath);
    if ($finfo) {
        finfo_close($finfo);
    }
    $mime = strtolower(trim($mime));

    // Inspect magic bytes from file header
    $handle = @fopen($tmpPath, 'rb');
    if (!$handle) {
        return ['ok' => false, 'has_file' => true, 'error' => $invalidMsg];
    }
    $header = fread($handle, 16);
    fclose($handle);

    if ($header === false || strlen($header) < 4) {
        return ['ok' => false, 'has_file' => true, 'error' => $invalidMsg];
    }

    $valid = false;
    $canonicalExt = $ext;

    // 1. PDF: must start with %PDF-
    if (str_starts_with($header, '%PDF-')) {
        $valid = ($ext === 'pdf') && ($mime === 'application/pdf' || $mime === 'application/x-pdf' || $mime === 'application/octet-stream');
        $canonicalExt = 'pdf';
    }
    // 2. JPEG: must start with \xFF\xD8\xFF
    elseif (str_starts_with($header, "\xFF\xD8\xFF")) {
        $valid = in_array($ext, ['jpg', 'jpeg'], true) && ($mime === 'image/jpeg' || $mime === 'image/pjpeg' || $mime === 'application/octet-stream');
        $canonicalExt = $ext === 'jpeg' ? 'jpeg' : 'jpg';
    }
    // 3. PNG: must start with \x89PNG (\x89\x50\x4E\x47)
    elseif (str_starts_with($header, "\x89\x50\x4E\x47") || str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
        $valid = ($ext === 'png') && ($mime === 'image/png' || $mime === 'application/octet-stream');
        $canonicalExt = 'png';
    }
    // 4. WEBP: RIFF at bytes 0..3 and WEBP at bytes 8..11
    elseif (strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
        $valid = ($ext === 'webp') && ($mime === 'image/webp' || $mime === 'application/octet-stream');
        $canonicalExt = 'webp';
    }

    if (!$valid) {
        return ['ok' => false, 'has_file' => true, 'error' => $invalidMsg];
    }

    return [
        'ok' => true,
        'has_file' => true,
        'mime' => $mime,
        'extension' => $canonicalExt,
        'size' => $size,
        'original_name' => $origName
    ];
}

/**
 * Recursively extracts and normalizes any nested $_FILES structure into a flat list of file records.
 */
function normalizeFilesArray($files): array {
    if (empty($files) || !is_array($files) || !isset($files['name'])) {
        return [];
    }

    $normalized = [];

    $extract = function ($names, $types, $tmpNames, $errors, $sizes) use (&$extract, &$normalized) {
        if (!is_array($names)) {
            $normalized[] = [
                'name' => $names,
                'type' => $types,
                'tmp_name' => $tmpNames,
                'error' => $errors,
                'size' => $sizes
            ];
            return;
        }

        foreach ($names as $key => $val) {
            $extract(
                $names[$key] ?? '',
                $types[$key] ?? '',
                $tmpNames[$key] ?? '',
                $errors[$key] ?? UPLOAD_ERR_NO_FILE,
                $sizes[$key] ?? 0
            );
        }
    };

    $extract($files['name'], $files['type'] ?? [], $files['tmp_name'] ?? [], $files['error'] ?? [], $files['size'] ?? []);
    return $normalized;
}

/**
 * Validates all files within a $_FILES entry.
 * If any uploaded file fails validation, returns failure with authoritative message immediately.
 */
function validateUploadedDocumentGroup($files): array {
    $normalized = normalizeFilesArray($files);
    foreach ($normalized as $single) {
        $val = validateUploadedDocument($single);
        if (!$val['ok']) {
            return $val;
        }
    }
    return ['ok' => true, 'count' => count($normalized)];
}
