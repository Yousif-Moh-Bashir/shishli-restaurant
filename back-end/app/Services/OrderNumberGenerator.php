<?php

namespace App\Services;

use Illuminate\Support\Str;

class OrderNumberGenerator
{
    public function forOrderId(int $orderId): string
    {
        return 'SH-'.str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);
    }

    /** Temporary unique value until the database assigns the order ID inside checkout's transaction. */
    public function generate(): string
    {
        return 'SH-'.now()->format('ymd').'-'.strtoupper((string) Str::ulid());
    }
}
