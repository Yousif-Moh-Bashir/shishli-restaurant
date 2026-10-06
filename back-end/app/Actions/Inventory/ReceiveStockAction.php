<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\BranchInventory;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveStockAction
{
    public function __construct(private InventoryService $stock) {}

    public function handle(BranchInventory $inventory, string|int|float $quantity, User $actor, ?string $reason = null, ?string $referenceUuid = null): BranchInventory
    {
        $amount = StockQuantity::minor($quantity);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون أكبر من صفر.']);
        }

        return DB::transaction(function () use ($inventory, $amount, $actor, $reason, $referenceUuid): BranchInventory {
            $inventory = BranchInventory::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $this->stock->mutate($inventory, StockMovementType::StockIn, $amount, $amount, 0, $actor, $reason, $referenceUuid, $referenceUuid === null ? null : 'external');

            return $inventory;
        }, 3);
    }
}
