<?php

namespace App\Services;

use App\Enums\OrderTiming;
use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\Order;
use Carbon\CarbonImmutable;

class OrderEstimateService
{
    /** Snapshot once at checkout; no live settings/product lookup during tracking. */
    public function snapshot(Order $order, Cart $cart, array $settings, ?DeliveryQuote $quote): void
    {
        $minutes = $cart->items->map(fn ($item) => $item->product->preparation_time)->filter(fn ($minutes): bool => $minutes !== null && $minutes > 0)->max()
            ?? $settings[$order->type === OrderType::Pickup ? 'pickup.default_preparation_minutes' : 'delivery.default_preparation_minutes'];
        $base = $order->order_timing === OrderTiming::Scheduled ? $order->scheduled_for : $order->placed_at;
        if ($base === null) {
            return;
        }
        $ready = CarbonImmutable::instance($base)->utc()->addMinutes((int) $minutes);
        $order->estimated_ready_at = $ready;
        if ($order->type === OrderType::Delivery && $quote !== null) {
            $order->estimated_delivery_from = $quote->estimatedMinMinutes === null ? null : $ready->addMinutes($quote->estimatedMinMinutes);
            $order->estimated_delivery_to = $quote->estimatedMaxMinutes === null ? null : $ready->addMinutes($quote->estimatedMaxMinutes);
        }
    }
}
