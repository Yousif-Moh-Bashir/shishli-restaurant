<?php

namespace App\Actions\Inventory;

use App\Models\BranchInventory;
use App\Services\StockQuantity;
use Illuminate\Support\Facades\DB;

class ConfigureInventoryAction
{
    public function handle(BranchInventory $inventory, array $data): BranchInventory
    {
        return DB::transaction(function () use ($inventory, $data): BranchInventory {
            $inventory = BranchInventory::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $values = [];
            if (array_key_exists('low_stock_threshold', $data)) {
                $values['low_stock_threshold'] = $data['low_stock_threshold'] === null ? null : StockQuantity::decimal(StockQuantity::minor($data['low_stock_threshold']));
            }
            if (array_key_exists('is_tracking', $data)) {
                $values['is_tracking'] = (bool) $data['is_tracking'];
            }
            $inventory->update($values);

            return $inventory;
        }, 3);
    }
}
