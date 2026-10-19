<?php

namespace App\Actions\Drivers;

use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Drivers\DriverOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionDeliveryAction
{
    public function __construct(private DriverOrderService $orders, private TransitionOrderStatusAction $transition) {}

    public function handle(User $driver, Order $order, DeliveryStatus $target, ?string $reason): Order
    {
        return DB::transaction(function () use ($driver, $order, $target, $reason): Order {
            $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->orders->owned($driver, $locked);
            abort_if(in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Completed], true), 409);
            $from = $locked->delivery_status;
            abort_unless($from !== null && ($target === $from->next() || ($target === DeliveryStatus::Failed && in_array($from, [DeliveryStatus::Assigned, DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true))), 409);
            if ($target === DeliveryStatus::PickedUp && $locked->status !== OrderStatus::Ready) {
                throw ValidationException::withMessages(['status' => 'انتظر حتى يصبح الطلب جاهزًا للاستلام.']);
            }
            if ($target === DeliveryStatus::Failed && (! is_string($reason) || mb_strlen(trim($reason)) < 3)) {
                throw ValidationException::withMessages(['reason' => 'اذكر سبب تعذر التسليم.']);
            }
            if ($target === DeliveryStatus::OnTheWay) {
                $locked = $this->transition->handle($locked, OrderStatus::OutForDelivery, $driver, OrderStatusSource::Driver, $reason);
            }
            if ($target === DeliveryStatus::Delivered) {
                abort_unless($locked->status === OrderStatus::OutForDelivery, 409);
                if ($locked->payment_status !== PaymentStatus::Paid) {
                    throw ValidationException::withMessages(['payment' => 'أكد تحصيل المبلغ قبل تسليم الطلب.']);
                }
            }
            $locked->delivery_status = $target;
            $locked->{'delivery_'.$target->value.'_at'} = now();
            $locked->save();
            $locked->deliveryHistory()->create(['driver_id' => $driver->id, 'changed_by' => $driver->id,
                'from_status' => $from->value, 'to_status' => $target->value, 'reason' => $reason]);

            return $locked;
        }, 3);
    }
}
