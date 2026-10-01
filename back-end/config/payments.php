<?php

return [
    'default_provider' => env('PAYMENT_DEFAULT_PROVIDER'),
    // Real adapters must be registered explicitly; there is no production fake.
    'providers' => [],
    'webhooks' => [
        'max_body_bytes' => (int) env('PAYMENT_WEBHOOK_MAX_BODY_BYTES', 65536),
        'timestamp_tolerance_seconds' => (int) env('PAYMENT_WEBHOOK_TIMESTAMP_TOLERANCE', 300),
    ],
];
