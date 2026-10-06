<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StockRecovered
{
    use Dispatchable;

    public function __construct(public readonly string $inventoryUuid, public readonly string $available) {}
}
