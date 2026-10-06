<?php

namespace App\Listeners;

use App\Events\LowStockDetected;
use App\Events\StockLevelChanged;
use App\Events\StockRecovered;
use App\Services\StockQuantity;

class DetectStockThresholdCrossing
{
    public function handle(StockLevelChanged $event): void
    {
        if (! $event->tracking || $event->threshold === null) {
            return;
        }
        if ($event->beforeAvailable > $event->threshold && $event->afterAvailable <= $event->threshold) {
            LowStockDetected::dispatch($event->inventoryUuid, StockQuantity::decimal($event->afterAvailable), StockQuantity::decimal($event->threshold));
        } elseif ($event->beforeAvailable <= $event->threshold && $event->afterAvailable > $event->threshold) {
            StockRecovered::dispatch($event->inventoryUuid, StockQuantity::decimal($event->afterAvailable));
        }
    }
}
