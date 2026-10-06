<?php

namespace App\Actions\Inventory;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReturnOrderStockAction
{
    public function __construct(private InventoryService $stock) {}

    /** Explicit full return of consumed quantities on a cancelled order, once per reservation; never called by cancellation itself. */
    public function handle(Order $order, User $actor, string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();
        DB::transaction(function () use ($order, $actor, $reason): void {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== OrderStatus::Cancelled) {
                throw ValidationException::withMessages(['order' => 'الإرجاع اليدوي متاح لطلب ملغى فقط.']);
            }
            $rows = $order->stockReservations()->where('status', StockReservationStatus::Consumed)->orderBy('branch_inventory_id')->orderBy('id')->lockForUpdate()->get();
            $inventories = BranchInventory::whereIn('id', $rows->pluck('branch_inventory_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($rows as $reservation) {
                $key = 'reservation:'.$reservation->uuid.':return';
                if ($inventories[$reservation->branch_inventory_id]->movements()->where('operation_key', $key)->exists()) {
                    continue;
                }
                $quantity = StockQuantity::minor($reservation->quantity);
                $this->stock->mutate($inventories[$reservation->branch_inventory_id], StockMovementType::Return, $quantity, $quantity, 0,
                    $actor, $reason, $order->uuid, 'order', $key);
            }
        }, 3);
    }
}
