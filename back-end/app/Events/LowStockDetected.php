<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class LowStockDetected
{
    use Dispatchable;

    public function __construct(public readonly string $inventoryUuid, public readonly string $available, public readonly string $threshold) {}
}
