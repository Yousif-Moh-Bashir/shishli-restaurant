<?php

namespace App\Services;

use App\Enums\InventoryIssueCode;
use App\Enums\StockMovementType;
use App\Events\StockLevelChanged;
use App\Models\BranchInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private InventoryAvailabilityService $availability) {}

    /** Callers hold the inventory row lock. Balances and the immutable ledger always share a transaction. */
    public function mutate(BranchInventory $inventory, StockMovementType $type, int $quantity, int $onHandDelta, int $reservedDelta,
        ?User $actor = null, ?string $reason = null, ?string $referenceUuid = null, ?string $referenceType = null, ?string $operationKey = null): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock mutations require a transaction and row lock.');
        }
        $onHand = StockQuantity::minor($inventory->on_hand);
        $reserved = StockQuantity::minor($inventory->reserved);
        $after = $onHand + $onHandDelta;
        $reservedAfter = $reserved + $reservedDelta;
        if ($quantity <= 0 || $after < 0 || $reservedAfter < 0 || $reservedAfter > $after || $after > StockQuantity::MAX) {
            $this->availability->fail(InventoryIssueCode::InvalidStockAdjustment, $inventory->product?->uuid);
        }
        $inventory->forceFill(['on_hand' => StockQuantity::decimal($after), 'reserved' => StockQuantity::decimal($reservedAfter)])->save();
        $movement = $inventory->movements()->create(['type' => $type, 'quantity' => StockQuantity::decimal($quantity),
            'on_hand_before' => StockQuantity::decimal($onHand), 'on_hand_after' => StockQuantity::decimal($after),
            'reserved_before' => StockQuantity::decimal($reserved), 'reserved_after' => StockQuantity::decimal($reservedAfter),
            'created_by' => $actor?->id, 'reason' => $reason, 'reference_uuid' => $referenceUuid,
            'reference_type' => $referenceType, 'operation_key' => $operationKey]);
        StockLevelChanged::dispatch($inventory->uuid, $inventory->branch_id, $inventory->product_id, $onHand - $reserved, $after - $reservedAfter,
            $inventory->low_stock_threshold === null ? null : StockQuantity::minor($inventory->low_stock_threshold), $inventory->is_tracking);

        return $movement;
    }
}
