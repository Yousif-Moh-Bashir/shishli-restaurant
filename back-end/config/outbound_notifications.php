<?php

use App\Services\Notifications\LaravelEmailProvider;

return [
    // External delivery always uses a durable queue; never use sync in production.
    'connection' => env('NOTIFICATION_QUEUE_CONNECTION', 'database'),
    'queue' => 'notifications',
    'max_attempts' => 3,
    'backoff' => [30, 120, 600],
    'timeout' => 30,
    'processing_lease_seconds' => 60,
    'channels' => [
        'sms' => ['enabled' => false, 'provider' => null],
        'whatsapp' => ['enabled' => false, 'provider' => null],
        'email' => ['enabled' => false, 'provider' => 'laravel'],
    ],
    'providers' => ['email' => ['laravel' => LaravelEmailProvider::class], 'sms' => [], 'whatsapp' => []],
    // No default country is inferred from a customer's local phone number.
    'phone_country' => null,
    'types' => [
        'order_placed' => ['whatsapp' => true],
        'order_confirmed' => ['whatsapp' => true],
        'order_preparing' => [],
        'order_ready' => ['whatsapp' => true],
        'order_out_for_delivery' => ['whatsapp' => true],
        'order_completed' => [],
        'order_cancelled' => ['whatsapp' => true],
        'payment_succeeded' => [],
        'payment_failed' => [],
    ],
    // Configure real, approved names for the selected WhatsApp provider before enabling it.
    'whatsapp_templates' => [],
];
