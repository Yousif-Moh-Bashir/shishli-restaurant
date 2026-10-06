<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;

class OrderStatusPresenter
{
    public function label(OrderStatus|string $status): string
    {
        $code = $status instanceof OrderStatus ? $status->value : $status;

        return match ($code) {
            'pending' => 'تم استلام الطلب', 'confirmed' => 'تم تأكيد الطلب', 'preparing' => 'جاري التحضير',
            'ready' => 'الطلب جاهز', 'out_for_delivery' => 'خرج للتوصيل', 'completed' => 'مكتمل', 'cancelled' => 'ملغي',
            default => 'حالة الطلب: '.$code,
        };
    }

    /** @return list<string> */
    public function flow(OrderType $type): array
    {
        return $type === OrderType::Delivery
            ? ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'completed']
            : ['pending', 'confirmed', 'preparing', 'ready', 'completed'];
    }
}
