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

function isRequestImageDocument($mime_type) {
    return in_array((string) $mime_type, ['image/jpeg', 'image/png', 'image/gif'], true);
}
