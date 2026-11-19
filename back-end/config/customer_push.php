<?php

return [
    'enabled' => env('CUSTOMER_PUSH_ENABLED', false),
    'starts_at' => env('CUSTOMER_PUSH_STARTS_AT'),
    'max_age_seconds' => 600,
    'public_key' => env('CUSTOMER_PUSH_PUBLIC_KEY'),
    'private_key' => env('CUSTOMER_PUSH_PRIVATE_KEY'),
    'subject' => env('CUSTOMER_PUSH_SUBJECT'),
];
