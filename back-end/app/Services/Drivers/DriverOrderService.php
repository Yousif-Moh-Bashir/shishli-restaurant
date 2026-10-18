<?php

namespace App\Services\Drivers;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\User;
use App\Services\BranchScheduleService;
use Illuminate\Database\Eloquent\Builder;

class DriverOrderService
{
    public const ACTIVE_STATUSES = ['assigned', 'picked_up', 'on_the_way', 'failed'];

    public function __construct(private BranchScheduleService $schedule) {}

    public function businessDay(User $driver, ?string $date = null): array
    {
        return $this->schedule->businessDayWindow($driver->driverBranch, $date);
    }

    public function authorize(User $driver): void
    {
        abort_unless($driver->is_active && $driver->hasRole('driver') && $driver->can('driver.access'), 403);
    }

    public function canAccept(User $driver): bool
    {
        return $driver->is_active && $driver->driver_enabled && $driver->driver_available && $driver->hasRole('driver')
            && $driver->can('driver.access') && $driver->driver_branch_id !== null
            && $driver->driverBranch !== null && $driver->driverBranch->deleted_at === null;
    }

    public function active(User $driver): Builder
    {
        return Order::where('driver_id', $driver->id)->whereIn('delivery_status', self::ACTIVE_STATUSES)
            ->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled]);
    }

    public function candidates(User $driver): Builder
    {
        return Order::without('promotions')->where('branch_id', $driver->driver_branch_id)->whereNotNull('branch_id')->where('type', OrderType::Delivery)->whereNull('driver_id')
            ->where(function (Builder $query): void {
                $query->where('status', OrderStatus::Ready)->orWhere(function (Builder $query): void {
                    $query->where('status', OrderStatus::Preparing)->whereNotNull('estimated_ready_at')
                        ->where('estimated_ready_at', '<=', now()->addMinutes(15));
                });
            });
    }

    public function available(User $driver): Builder
    {
        $query = $this->candidates($driver)->where('status', OrderStatus::Ready);
        $window = $this->businessDay($driver);
        $query->where('placed_at', '>=', $window['start'])->where('placed_at', '<', $window['end']);
        if (! $this->canAccept($driver) || $this->active($driver)->exists()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function listingContext(User $driver): array
    {
        $active = $this->active($driver)->where('branch_id', $driver->driver_branch_id)->first();
        $reason = match (true) {
            ! $driver->driver_enabled => 'حسابك موقوف عن استقبال الطلبات. تواصل مع إدارة الفرع.',
            $driver->driver_branch_id === null || $driver->driverBranch === null => 'لم تُربط بفرع. تواصل مع الإدارة لربط حسابك بالفرع الصحيح.',
            ! $driver->driver_available => 'أنت غير متاح حاليًا. فعّل حالة التوفر أعلى الصفحة لقبول طلب.',
            $active !== null => 'لديك طلب نشط. أكمله من «طلبك الحالي» قبل قبول طلب آخر.',
            ! $this->canAccept($driver) => 'استقبال الطلبات غير متاح لحسابك حاليًا. تواصل مع الإدارة.',
            default => null,
        };

        return ['can_accept' => $reason === null, 'blocking_reason' => $reason,
            'active_order' => $active ? ['id' => $active->uuid, 'order_number' => $active->order_number,
                'preparation_status' => $active->status->value, 'delivery_status' => $active->delivery_status?->value,
                'placed_at' => $active->placed_at?->toISOString()] : null];
    }

    public function owned(User $driver, Order $order): void
    {
        $this->authorize($driver);
        abort_unless($order->driver_id === $driver->id && $order->branch_id === $driver->driver_branch_id, 404);
    }

    public function actions(User $driver, Order $order): array
    {
        if ($order->driver_id !== $driver->id || $order->branch_id !== $driver->driver_branch_id
            || in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Completed], true)) {
            return [];
        }
        $actions = [];
        $next = $order->delivery_status?->next();
        if ($next !== null && ($next !== DeliveryStatus::PickedUp || $order->status === OrderStatus::Ready)
            && ($next !== DeliveryStatus::Delivered || $order->payment_status->value === 'paid')) {
            $actions[] = $next->value;
        }
        if (in_array($order->delivery_status, [DeliveryStatus::Assigned, DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true)) {
            $actions[] = 'failed';
        }
        if ($order->payment_method->value === 'cash' && $order->payment_status->value === 'pending'
            && in_array($order->delivery_status, [DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true)) {
            $actions[] = 'collect_cash';
        }

        if ($order->payment_method->isOffline() && $order->payment_status->value === 'pending'
            && in_array($order->delivery_status, [DeliveryStatus::PickedUp, DeliveryStatus::OnTheWay], true)) {
            $actions[] = 'collect_payment';
        }

        return $actions;
    }
}
