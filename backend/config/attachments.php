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

    /*
    |---------------------------------------------------------------------------
    | Detected MIME types accepted per extension
    |---------------------------------------------------------------------------
    |
    | The extension whitelist above is the security boundary. This map exists
    | because content sniffing does not return the "official" MIME type for
    | Office documents:
    |
    |   - .docx / .xlsx are ZIP containers, so libmagic reports application/zip
    |     unless its OOXML rule matches, which depends on the zip entry order
    |     and the installed magic database. Real files routinely sniff as zip.
    |   - .doc / .xls are OLE2 compound documents, reported as
    |     application/x-ole-storage or application/CDFV2.
    |
    | Checking the sniffed type against a flat list of official types rejected
    | every Office upload with a 422 while the UI advertised them as allowed.
    |
    */
    'allowed_mime_types_by_extension' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'doc' => [
            'application/msword',
            'application/x-ole-storage',
            'application/CDFV2',
        ],
        'xls' => [
            'application/vnd.ms-excel',
            'application/x-ole-storage',
            'application/CDFV2',
        ],
    ],
];
