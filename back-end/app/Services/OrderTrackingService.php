<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;

class OrderTrackingService
{
    public function __construct(private OrderStatusPresenter $statuses) {}

    public function buildTrackingData(Order $order): OrderTrackingData
    {
        $order->loadMissing(['branch', 'statusHistory', 'items.options', 'address']);
        $flow = $this->statuses->flow($order->type);
        $status = (string) $order->getRawOriginal('status');
        $cancelled = $status === OrderStatus::Cancelled->value;
        // Read raw codes so a future historical status cannot fail enum casting.
        $histories = $order->statusHistory->groupBy(fn ($history): string => (string) $history->getRawOriginal('to_status'));
        $position = array_search($status, $flow, true);
        $step = $position === false ? null : $position + 1;
        $timeline = [];
        foreach ($flow as $index => $code) {
            $history = $histories->get($code)?->first();
            if ($cancelled && $history === null) {
                continue;
            }
            $state = $cancelled ? 'completed' : ($code === $status ? 'current' : ($step !== null && $index + 1 < $step ? 'completed' : 'upcoming'));
            if ($status === 'completed') {
                $state = 'completed';
            }
            $timeline[] = ['status' => $code, 'label' => $this->statuses->label($code), 'state' => $state,
                'is_completed' => $state === 'completed', 'occurred_at' => $state === 'upcoming' ? null : $history?->created_at?->toISOString()];
        }
        $known = [...$flow, 'cancelled'];
        foreach ($order->statusHistory as $history) {
            $code = (string) $history->getRawOriginal('to_status');
            if (! in_array($code, $known, true)) {
                $timeline[] = ['status' => $code, 'label' => $this->statuses->label($code), 'state' => $code === $status ? 'current' : 'completed',
                    'is_completed' => $code !== $status, 'occurred_at' => $history->created_at?->toISOString()];
            }
        }
        if (! $cancelled && $position === false && ! $histories->has($status)) {
            $timeline[] = ['status' => $status, 'label' => $this->statuses->label($status), 'state' => 'current', 'is_completed' => false, 'occurred_at' => null];
        }
        if ($cancelled) {
            $timeline[] = ['status' => 'cancelled', 'label' => $this->statuses->label('cancelled'), 'state' => 'cancelled',
                'is_completed' => false, 'occurred_at' => $histories->get('cancelled')?->first()?->created_at?->toISOString()];
        }
        $latest = $order->updated_at;
        foreach ($order->statusHistory as $history) {
            if ($history->created_at !== null && ($latest === null || $history->created_at->greaterThan($latest))) {
                $latest = $history->created_at;
            }
        }
        $phone = $order->customer_phone;
        $masked = mb_strlen($phone) > 6 ? mb_substr($phone, 0, 3).str_repeat('*', mb_strlen($phone) - 6).mb_substr($phone, -3) : '***';

        return new OrderTrackingData(
            ['uuid' => $order->uuid, 'number' => $order->order_number, 'type' => $order->type->value,
                'delivery_state' => ['status' => $order->delivery_status?->value, 'label' => $order->delivery_status?->label(),
                    'under_review' => $order->delivery_exception_requested_at !== null],
                'status' => ['code' => $status, 'label' => $this->statuses->label($status)],
                'order_timing' => $order->order_timing->value, 'scheduled_for' => $order->scheduled_for?->toISOString(),
                'placed_at' => $order->placed_at?->toISOString(), 'completed_at' => $order->completed_at?->toISOString(),
                'cancelled_at' => $order->cancelled_at?->toISOString()],
            ['current_step' => $cancelled ? null : $step, 'total_steps' => count($flow),
                'percentage' => $cancelled || $step === null ? null : (int) round($step / count($flow) * 100), 'is_cancelled' => $cancelled],
            $timeline,
            ['ready_at' => $order->estimated_ready_at?->toISOString(), 'delivery_from' => $order->estimated_delivery_from?->toISOString(),
                'delivery_to' => $order->estimated_delivery_to?->toISOString(), 'is_estimated' => true],
            ['id' => $order->branch->uuid, 'name' => $order->branch->name, 'phone' => $order->branch->phone, 'address' => $order->branch->address],
            ['method' => $order->payment_method->value, 'status' => $order->payment_status->value],
            ['phone_masked' => $masked],
            $order->items->map(fn (OrderItem $item): array => ['uuid' => $item->uuid, 'name' => $item->product_name, 'quantity' => $item->quantity,
                'unit_price' => $item->unit_price, 'line_total_after_discount' => $item->line_total_after_discount,
                'options' => $item->options->map(fn (OrderItemOption $option): array => ['group' => $option->option_group_name, 'name' => $option->option_value_name])->all()])->all(),
            ['subtotal' => $order->subtotal, 'discount_total' => $order->discount_total, 'delivery_fee' => $order->delivery_fee,
                'tax_total' => $order->tax_total, 'total' => $order->total, 'currency' => $order->currency],
            $order->address === null ? null : ['district' => $order->address->district, 'street' => $order->address->street],
            $latest?->toISOString(),
        );
    }
}
