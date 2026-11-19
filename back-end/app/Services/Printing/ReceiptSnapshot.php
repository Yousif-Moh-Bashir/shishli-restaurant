<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\OrderStatusHistory;

class ReceiptSnapshot
{
    /** @return array<string, mixed> */
    public function make(Order $order, OrderStatusHistory $acceptance, string $destination): array
    {
        $order->loadMissing(['items.options', 'address', 'branch']);
        $cashier = $destination === 'cashier';
        $receipt = [
            'version' => 1,
            'destination' => $destination,
            'order_number' => $order->order_number,
            'type' => $order->type->value,
            'accepted_at' => $acceptance->created_at->toIso8601String(),
            'scheduled_for' => $order->scheduled_for?->toIso8601String(),
            'timezone' => config('app.timezone'),
            'branch_name' => $order->branch->name,
            'notes' => $order->customer_notes,
            'items' => $order->items->map(function ($item) use ($cashier): array {
                $data = ['name' => $item->product_name, 'quantity' => $item->quantity,
                    'options' => $item->options->map(fn ($option): array => $cashier
                        ? ['group' => $option->option_group_name, 'value' => $option->option_value_name, 'price' => $option->price_modifier]
                        : ['group' => $option->option_group_name, 'value' => $option->option_value_name])->all()];
                if ($cashier) {
                    $data['pricing'] = ['unit_price' => $item->unit_price, 'line_total' => $item->line_total,
                        'discount' => $item->discount_total, 'after_discount' => $item->line_total_after_discount];
                }

                return $data;
            })->all(),
        ];
        if ($cashier) {
            $receipt['totals'] = ['subtotal' => $order->subtotal, 'discount' => $order->discount_total,
                'tax' => $order->tax_total, 'delivery_fee' => $order->delivery_fee, 'total' => $order->total, 'currency' => $order->currency];
            $receipt['payment_method'] = $order->payment_method->value;
            $receipt['payment_status'] = $order->payment_status->value;
            $receipt['customer'] = ['name' => $order->customer_name, 'phone' => $order->customer_phone];
            $receipt['address'] = $order->type->value === 'delivery' && $order->address
                ? $order->address->only(['recipient_name', 'phone', 'city', 'district', 'street', 'building_number', 'floor', 'apartment', 'landmark', 'notes']) : null;
        }

        return $receipt;
    }
}
