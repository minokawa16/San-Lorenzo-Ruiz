<?php

/** Canonical validation policy for request-document uploads. */
function getRequestDocumentConfig() {
    return [
        'max_size' => 10 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
        'mime_types' => [
            'image/jpeg', 'image/png', 'image/gif', 'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
        ],
    ];
}

function isRequestImageDocument($mime_type, $filename = '') {
    $clean_mime = strtolower(trim((string) $mime_type));
    if ($clean_mime !== '' && str_starts_with($clean_mime, 'image/')) {
        return true;
    }
    if ($filename !== '') {
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }
    return in_array($clean_mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml'], true);
}
