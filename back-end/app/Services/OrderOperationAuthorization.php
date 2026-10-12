<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\OrderType;
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
        if ($source === OrderStatusSource::Driver) {
            abort_unless($actor !== null && $actor->is_active && $actor->hasRole('driver') && $actor->can('driver.access')
                && $order->driver_id === $actor->id && $order->branch_id === $actor->driver_branch_id, 403);
            abort_unless(($target === OrderStatus::OutForDelivery && $order->delivery_status === DeliveryStatus::PickedUp)
                || ($target === OrderStatus::Completed && $order->delivery_status === DeliveryStatus::OnTheWay), 403);

            return;
        }
        if ($order->driver_id !== null && in_array($target, [OrderStatus::OutForDelivery, OrderStatus::Completed], true)) {
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
        if ($order->type === OrderType::Delivery && $target === OrderStatus::OutForDelivery) {
            abort(409, 'أسند الطلب إلى مندوب. يبدأ المندوب التوصيل بعد استلام الطلب من الفرع.');
        }
        if ($target === OrderStatus::Completed && $order->type === OrderType::Delivery
            && $actor->hasRole('cashier') && ! $actor->hasAnyRole(['super_admin', 'manager'])) {
            abort(403);
        }
    }
}
