<?php

/** Centralized safe file validation, storage, and streaming helpers. */
function secureValidateUpload(array $file, array $config): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['ok' => false, 'error' => 'The uploaded file could not be validated.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > (int) ($config['max_size'] ?? 0)) return ['ok' => false, 'error' => 'The uploaded file size is not allowed.'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = $config['mime_types'] ?? [];
    if (!isset($allowed[$mime]) || !in_array($extension, (array) ($config['extensions'] ?? []), true)) return ['ok' => false, 'error' => 'The uploaded file type is not allowed.'];
    return ['ok' => true, 'mime' => $mime, 'extension' => $allowed[$mime], 'size' => $size, 'original_name' => basename((string) $file['name'])];
}

function secureStoreUpload(array $file, string $directory, array $config): array {
    $validated = secureValidateUpload($file, $config);
    if (!$validated['ok']) return $validated;
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) return ['ok' => false, 'error' => 'Unable to prepare secure storage.'];
    $filename = bin2hex(random_bytes(24)) . '.' . $validated['extension'];
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) return ['ok' => false, 'error' => 'Unable to store the uploaded file.'];
    @chmod($path, 0600);
    return $validated + ['ok' => true, 'filename' => $filename, 'path' => $path];
}

function secureStreamFile(string $path, string $mime, string $filename, bool $inline = false): void {
    $real = realpath($path);
    if (!$real || !is_file($real)) { http_response_code(404); exit('File not found.'); }
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($filename));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($real));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}
