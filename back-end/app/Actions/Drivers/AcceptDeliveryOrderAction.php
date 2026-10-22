<?php

namespace App\Actions\Drivers;

use App\Enums\DeliveryStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Drivers\DriverOrderService;
use Illuminate\Support\Facades\DB;

class AcceptDeliveryOrderAction
{
    public function __construct(private DriverOrderService $orders) {}

    public function handle(User $driver, Order $order): Order
    {
        return DB::transaction(function () use ($driver, $order): Order {
            $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $this->orders->authorize($driver);
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->branch_id === $driver->driver_branch_id, 404);
            abort_unless($this->orders->canAccept($driver), 403);
            abort_if($this->orders->activeForUpdate($driver) !== null || $locked->driver_id !== null, 409);
            abort_unless($this->orders->available($driver)->whereKey($locked->id)->exists(), 409);
            $locked->driver_id = $driver->id;
            $locked->delivery_status = DeliveryStatus::Assigned;
            $locked->delivery_revision++;
            $locked->driver_accepted_at = now();
            $locked->save();
            $locked->deliveryHistory()->create(['driver_id' => $driver->id, 'changed_by' => $driver->id, 'to_status' => 'assigned']);

            return $locked;
        }, 3);
    }
}
