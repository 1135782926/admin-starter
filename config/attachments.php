<?php

return [
    'max_size' => (int) env('ATTACHMENT_MAX_SIZE', 50 * 1024 * 1024),
    'temporary_url_ttl' => (int) env('ATTACHMENT_TEMPORARY_URL_TTL', 300),
    'uploads_per_minute' => (int) env('ATTACHMENT_UPLOADS_PER_MINUTE', 30),
    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    'mime_types' => [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ],
];
