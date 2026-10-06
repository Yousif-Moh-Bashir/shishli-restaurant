<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockQuantity;
use Illuminate\Support\Facades\DB;

class ReleaseOrderStockAction
{
    public function __construct(private InventoryService $stock) {}

    public function handle(Order $order, ?User $actor = null): void
    {
        DB::transaction(function () use ($order, $actor): void {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $rows = $order->stockReservations()->where('status', StockReservationStatus::Active)->orderBy('branch_inventory_id')->orderBy('id')->lockForUpdate()->get();
            $inventories = BranchInventory::whereIn('id', $rows->pluck('branch_inventory_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($rows as $reservation) {
                $quantity = StockQuantity::minor($reservation->quantity);
                $this->stock->mutate($inventories[$reservation->branch_inventory_id], StockMovementType::ReservationRelease, $quantity, 0, -$quantity,
                    $actor, referenceUuid: $order->uuid, referenceType: 'order', operationKey: 'reservation:'.$reservation->uuid.':release');
                $reservation->update(['status' => StockReservationStatus::Released, 'released_at' => now()]);
            }
        }, 3);
    }
}
