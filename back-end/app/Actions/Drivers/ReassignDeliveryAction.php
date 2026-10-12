<?php

namespace App\Actions\Drivers;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use App\Services\Drivers\DriverOrderService;
use Illuminate\Support\Facades\DB;

class ReassignDeliveryAction
{
    public function __construct(private DriverOrderService $orders, private AdminActivityService $audit) {}

    public function handle(User $actor, Order $order, User $target, string $reason): Order
    {
        abort_unless($actor->can('drivers.reassign'), 403);

        return DB::transaction(function () use ($actor, $order, $target, $reason): Order {
            $previousId = $order->driver_id;
            $drivers = User::whereIn('id', array_filter([$previousId, $target->id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->driver_id !== $previousId, 409);
            $target = $drivers->get($target->id);
            abort_unless($target !== null && $this->orders->canAccept($target), 422);
            abort_unless($locked->branch_id === $target->driver_branch_id && $locked->type === OrderType::Delivery, 422);
            abort_if(($locked->driver_id === $target->id && $locked->delivery_status !== DeliveryStatus::Failed) || $this->orders->active($target)->whereKeyNot($locked->id)->exists()
                || in_array($locked->status, [OrderStatus::Completed, OrderStatus::Cancelled], true), 409);
            abort_unless(in_array($locked->status, [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery], true), 409);
            $from = $locked->delivery_status;
            $status = $locked->status === OrderStatus::OutForDelivery ? DeliveryStatus::OnTheWay : DeliveryStatus::Assigned;
            if ($from === DeliveryStatus::PickedUp || ($from === DeliveryStatus::Failed && $locked->delivery_picked_up_at !== null && $locked->status === OrderStatus::Ready)) {
                $status = DeliveryStatus::PickedUp;
            }
            $locked->driver_id = $target->id;
            $locked->delivery_status = $status;
            $locked->driver_accepted_at = now();
            $locked->delivery_failed_at = null;
            $locked->save();
            $locked->deliveryHistory()->create(['driver_id' => $target->id, 'previous_driver_id' => $previousId,
                'changed_by' => $actor->id, 'from_status' => $from?->value, 'to_status' => $status->value, 'reason' => $reason]);
            $this->audit->record($actor, 'drivers.reassign', 'order', $locked->uuid, ['driver_uuid' => $drivers->get($previousId)?->uuid],
                ['driver_uuid' => $target->uuid, 'status' => $status->value], $reason);

            return $locked;
        }, 3);
    }
}
