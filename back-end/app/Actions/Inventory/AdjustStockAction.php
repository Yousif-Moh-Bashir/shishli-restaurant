<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\BranchInventory;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdjustStockAction
{
    public function __construct(private InventoryService $stock) {}

    public function handle(BranchInventory $inventory, string|int|float $quantity, User $actor, string $reason, bool $waste = false): BranchInventory
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();
        $amount = StockQuantity::minor($quantity, signed: ! $waste);
        if ($amount === 0 || ($waste && $amount < 0)) {
            throw ValidationException::withMessages(['quantity' => 'الكمية غير صالحة.']);
        }
        if ($waste) {
            $amount = -$amount;
        }
        $type = $waste ? StockMovementType::Waste : ($amount > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut);

        return DB::transaction(function () use ($inventory, $amount, $type, $actor, $reason): BranchInventory {
            $inventory = BranchInventory::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $this->stock->mutate($inventory, $type, abs($amount), $amount, 0, $actor, trim($reason));

            return $inventory;
        }, 3);
    }
}
