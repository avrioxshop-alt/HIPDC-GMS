<?php

return [
    'name' => 'HIPDC-GMS',
    'environment' => getenv('HIPDC_APP_ENV') ?: 'development',
    'response' => [
        'content_type' => 'application/json; charset=utf-8',
        'cache_control' => 'no-store, no-cache, must-revalidate, max-age=0',
    ],
    'session' => [
        'name' => 'HIPDCSESSID',
        'path' => getenv('HIPDC_SESSION_PATH') ?: null,
        'secure' => null,
        'http_only' => true,
        'same_site' => 'Lax',
        'lifetime' => 0,
    ],
    'errors' => [
        'display' => false,
        'log' => true,
    ],
];
