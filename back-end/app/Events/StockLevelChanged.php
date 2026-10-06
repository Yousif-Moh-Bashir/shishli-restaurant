<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StockLevelChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $inventoryUuid, public readonly int $branchId, public readonly int $productId,
        public readonly int $beforeAvailable, public readonly int $afterAvailable, public readonly ?int $threshold, public readonly bool $tracking) {}
}
