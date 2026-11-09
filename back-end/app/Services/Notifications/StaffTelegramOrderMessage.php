<?php

namespace App\Services\Notifications;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\Order;

class StaffTelegramOrderMessage
{
    public function render(Order $order, string $detailsUrl): string
    {
        $order->loadMissing(['branch', 'items.options']);
        $payment = match ($order->payment_method) {
            PaymentMethod::Cash => 'نقدي',
            PaymentMethod::Card => 'بطاقة إلكترونية',
            PaymentMethod::Network => 'شبكة',
        };
        $lines = [
            'طلب جديد #'.$this->singleLine($order->order_number),
            'الفرع: '.$this->singleLine($order->branch->name),
            'نوع الطلب: '.($order->type === OrderType::Delivery ? 'توصيل' : 'استلام من الفرع'),
            'طريقة الدفع: '.$payment,
            'الإجمالي: '.$order->total.' '.$this->singleLine($order->currency),
            '',
            'الأصناف والكميات:',
        ];
        foreach ($order->items->sortBy('id') as $item) {
            $lines[] = '- '.$this->singleLine($item->product_name).' × '.$item->quantity;
            foreach ($item->options as $option) {
                $lines[] = '  • '.$this->singleLine($option->option_group_name).': '.$this->singleLine($option->option_value_name);
            }
        }
        $lines[] = '';
        $lines[] = 'تفاصيل الطلب: '.$detailsUrl;

        return implode("\n", $lines);
    }

    private function singleLine(string $value): string
    {
        return trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '');
    }
}
