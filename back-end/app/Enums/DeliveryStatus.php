<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case OnTheWay = 'on_the_way';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function next(): ?self
    {
        return match ($this) {
            self::Assigned => self::PickedUp,
            self::PickedUp => self::OnTheWay,
            self::OnTheWay => self::Delivered,
            self::Delivered, self::Failed => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'تم إسناد الطلب للمندوب',
            self::PickedUp => 'استلم المندوب من الفرع',
            self::OnTheWay => 'في الطريق للعميل',
            self::Delivered => 'تم التسليم للعميل',
            self::Failed => 'تعذر التسليم',
        };
    }
}
