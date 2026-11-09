<?php

return [
    'enabled' => env('STAFF_WEB_PUSH_PILOT_ENABLED', false),
    'user_uuid' => env('STAFF_WEB_PUSH_PILOT_USER_UUID'),
    'campaign_uuid' => env('STAFF_WEB_PUSH_PILOT_CAMPAIGN_UUID'),
    'expires_at' => env('STAFF_WEB_PUSH_PILOT_EXPIRES_AT'),
    'origin' => 'https://shishlifresh.com',
    'vapid' => [
        'public_key' => env('STAFF_WEB_PUSH_PUBLIC_KEY'),
        'private_key' => env('STAFF_WEB_PUSH_PRIVATE_KEY'),
        'subject' => env('STAFF_WEB_PUSH_SUBJECT'),
    ],
];
