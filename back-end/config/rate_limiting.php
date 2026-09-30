<?php

return [
    'delivery-quote' => ['per_minute' => 120],
    'cart' => ['per_minute' => 120, 'ip_per_minute' => 300],
    'cart-create' => ['per_minute' => 30],
    'login' => ['per_minute' => 5, 'ip_per_minute' => 30],
    'register' => ['per_minute' => 6],
    'otp' => ['per_minute' => 5, 'ip_per_minute' => 15],
    'forgot-password' => ['per_minute' => 3, 'ip_per_minute' => 10],
    'checkout' => ['per_minute' => 10],
    'coupons' => ['per_minute' => 5],
];
