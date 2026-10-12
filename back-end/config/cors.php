<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',',
        trim((string) env('CORS_ALLOWED_ORIGINS', ''))
            ?: (in_array(env('APP_ENV'), ['local', 'testing'], true) ? '*' : '')
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-ID', 'Retry-After'],
    'max_age' => 0,
    'supports_credentials' => false,
];
