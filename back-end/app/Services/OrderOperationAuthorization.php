<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OrderOperationAuthorization
{
    public function __construct(private GuestOrderAccess $guestAccess) {}

    public function staffSource(User $actor): OrderStatusSource
    {
        if ($actor->hasAnyRole(['super_admin', 'manager'])) {
            return OrderStatusSource::Admin;
        }
        if ($actor->hasRole('cashier')) {
            return OrderStatusSource::Cashier;
        }
        if ($actor->hasRole('kitchen')) {
            return OrderStatusSource::Kitchen;
        }

        return OrderStatusSource::Admin;
    }

    public function authorize(Order $order, OrderStatus $target, ?User $actor, OrderStatusSource $source, ?string $guestToken = null): void
    {
        if ($source === OrderStatusSource::DeliveryException) {
            abort_unless($actor !== null && $actor->is_active && $actor->can('delivery_exceptions.cancel')
                && $target === OrderStatus::Cancelled && $order->type === OrderType::Delivery
                && $order->delivery_task_closed_at !== null
                && in_array($order->delivery_status, [DeliveryStatus::Returned, DeliveryStatus::Released], true), 403);

            return;
        }
        if ($source === OrderStatusSource::Driver) {
            abort_unless($actor !== null && $actor->is_active && $actor->hasRole('driver') && $actor->can('driver.access')
                && $order->driver_id === $actor->id && $order->branch_id === $actor->driver_branch_id, 403);
            $dispatching = $target === OrderStatus::OutForDelivery && $order->delivery_status === DeliveryStatus::PickedUp;
            $completingDelivery = $target === OrderStatus::Completed && $order->type === OrderType::Delivery
                && $order->status === OrderStatus::OutForDelivery && $order->delivery_status === DeliveryStatus::Delivered
                && $order->payment_status === PaymentStatus::Paid;
            abort_unless($dispatching || $completingDelivery, 403);

            return;
        }
        if ($order->driver_id !== null && $target === OrderStatus::OutForDelivery) {
            abort(409);
        }
        if ($source === OrderStatusSource::Customer) {
            abort_unless($target === OrderStatus::Cancelled, 403);
            if ($actor === null) {
                $this->guestAccess->authorize($order, $guestToken);
            } else {
                abort_unless($order->user_id === $actor->id, 404);
            }
            if ($order->status !== OrderStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'يمكن للعميل إلغاء الطلب قبل تأكيد المطعم فقط.']);
            }

            return;
        }
        abort_unless($actor !== null && $source === $this->staffSource($actor), 403);
        if ($source === OrderStatusSource::Kitchen) {
            abort_unless(in_array($target, [OrderStatus::Preparing, OrderStatus::Ready], true), 403);
        }
        $permission = match ($target) {
            OrderStatus::Confirmed => 'orders.confirm',
            OrderStatus::Preparing => 'orders.start_preparing',
            OrderStatus::Ready => 'orders.mark_ready',
            OrderStatus::OutForDelivery => 'orders.dispatch',
            OrderStatus::Completed => 'orders.complete',
            OrderStatus::Cancelled => 'orders.cancel',
            default => null,
        };
        abort_unless($permission !== null && $actor->can($permission), 403);
        if ($target === OrderStatus::Cancelled && $order->driver_id !== null && $order->delivery_task_closed_at === null
            && ($order->delivery_picked_up_at !== null || $order->delivery_on_the_way_at !== null
                || in_array($order->delivery_status, [DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay, DeliveryStatus::Returning], true))) {
            abort(409, 'الطلب في عهدة المندوب. عالج الإرجاع وحسم العهدة من استثناءات التوصيل قبل الإلغاء.');
        }
        if ($order->type === OrderType::Delivery && $target === OrderStatus::OutForDelivery) {
            abort(409, 'أسند الطلب إلى مندوب. يبدأ المندوب التوصيل بعد استلام الطلب من الفرع.');
        }
        if ($target === OrderStatus::Completed && $order->type === OrderType::Delivery && $order->status === OrderStatus::OutForDelivery) {
            abort_unless($order->delivery_status === DeliveryStatus::Delivered
                && $order->payment_status === PaymentStatus::Paid, 409);
        }
    }
}
