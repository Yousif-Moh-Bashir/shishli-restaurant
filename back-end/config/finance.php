<?php

return [
    'timezone' => env('FINANCE_TIMEZONE', 'Asia/Riyadh'),
    'currency' => 'SAR',
    'host' => env('FINANCE_HOST', 'finance.shishlifresh.com'),
    'token_minutes' => 480,
    'local_hosts' => ['localhost', '127.0.0.1', '::1'],
];
