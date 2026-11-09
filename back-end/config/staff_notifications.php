<?php

return [
    'enabled' => env('STAFF_NOTIFICATIONS_ENABLED', false),
    // Set an explicit UTC activation time for each trial. Never backfill orders.
    'starts_at' => env('STAFF_NOTIFICATIONS_STARTS_AT'),
    'connection' => env('STAFF_NOTIFICATIONS_QUEUE_CONNECTION', env('NOTIFICATION_QUEUE_CONNECTION', 'database')),
    'queue' => 'notifications',
    'max_age_seconds' => 1800,
    'sound' => ['enabled' => env('STAFF_NOTIFICATIONS_SOUND_ENABLED', false), 'repeat_seconds' => 60],
    'push_diagnostics' => [
        'enabled' => env('STAFF_WEB_PUSH_DIAGNOSTIC_ENABLED', false),
        'origin' => env('STAFF_WEB_PUSH_DIAGNOSTIC_ORIGIN'),
        'subscription_seconds' => 28800,
    ],
    'push' => [
        'enabled' => env('STAFF_NOTIFICATIONS_PUSH_ENABLED', false),
        'subject' => env('STAFF_WEB_PUSH_SUBJECT'),
        'public_key' => env('STAFF_WEB_PUSH_PUBLIC_KEY'),
        'private_key' => env('STAFF_WEB_PUSH_PRIVATE_KEY'),
    ],
    'telegram' => [
        'enabled' => env('STAFF_NOTIFICATIONS_TELEGRAM_ENABLED', false),
        'token' => env('STAFF_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('STAFF_TELEGRAM_CHAT_ID'),
    ],
];
