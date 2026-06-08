<?php

return [
    'max_size_bytes' => env('ATMS_ATTACHMENT_MAX_SIZE', 20 * 1024 * 1024),

    'allowed_mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],

    'allowed_extensions' => [
        'pdf',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'doc',
        'docx',
        'xls',
        'xlsx',
    ],
];
