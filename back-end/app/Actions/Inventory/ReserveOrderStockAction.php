<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\Product;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryService;
use App\Services\StockQuantity;
use Illuminate\Support\Facades\DB;

class ReserveOrderStockAction
{
    public function __construct(private InventoryService $stock, private InventoryAvailabilityService $availability) {}

    public function handle(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->filter())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $tracked = $items->filter(fn ($item): bool => $products->get($item->product_id)?->tracks_inventory ?? false);
            // One locking SELECT ordered by inventory ID for all variants of every tracked product.
            $inventories = BranchInventory::where('branch_id', $order->branch_id)->whereIn('product_id', $tracked->pluck('product_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('product_id');
            $existing = $order->stockReservations()->get()->keyBy('order_item_id');
            foreach ($tracked as $item) {
                if ($existing->has($item->id)) {
                    continue;
                }
                $inventory = $inventories->get($item->product_id);
                $required = $item->quantity * 1000;
                $issue = $this->availability->issue($inventory, $required);
                if ($issue !== null) {
                    $this->availability->fail($issue, $item->product_uuid);
                }
                if (! $inventory->is_tracking) {
                    continue;
                }
                $reservation = $inventory->reservations()->create(['order_id' => $order->id, 'order_item_id' => $item->id,
                    'quantity' => StockQuantity::decimal($required), 'status' => StockReservationStatus::Active, 'reserved_at' => now()]);
                $this->stock->mutate($inventory, StockMovementType::Reservation, $required, 0, $required,
                    referenceUuid: $order->uuid, referenceType: 'order', operationKey: 'reservation:'.$reservation->uuid.':reserve');
            }
        }, 3);
    }
}
