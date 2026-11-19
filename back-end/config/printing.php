<?php

return [
    'enabled' => env('ORDER_PRINTING_ENABLED', false),
    'allow_live' => env('ORDER_PRINTING_ALLOW_LIVE', false),
    'lease_seconds' => 120,
    'execution_seconds' => 180,
    'max_attempts' => 3,
    'max_age_minutes' => 30,
];
