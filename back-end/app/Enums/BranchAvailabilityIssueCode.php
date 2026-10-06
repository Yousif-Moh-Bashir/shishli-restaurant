<?php

namespace App\Enums;

enum BranchAvailabilityIssueCode: string
{
    case OrderingMaintenance = 'ORDERING_MAINTENANCE';
    case RestaurantOrdersDisabled = 'RESTAURANT_ORDERS_DISABLED';
    case BranchInactive = 'BRANCH_INACTIVE';
    case BranchNotAcceptingOrders = 'BRANCH_NOT_ACCEPTING_ORDERS';
    case BranchTemporarilyClosed = 'BRANCH_TEMPORARILY_CLOSED';
    case BranchClosed = 'BRANCH_CLOSED';
    case PickupDisabled = 'PICKUP_DISABLED';
    case DeliveryDisabled = 'DELIVERY_DISABLED';
    case PickupNotSupported = 'PICKUP_NOT_SUPPORTED';
    case DeliveryNotSupported = 'DELIVERY_NOT_SUPPORTED';

    public function message(): string
    {
        return match ($this) {
            self::OrderingMaintenance => 'الطلب غير متاح مؤقتًا، يرجى المحاولة لاحقًا.',
            self::RestaurantOrdersDisabled => 'استقبال الطلبات معطل حاليًا.',
            self::BranchInactive => 'الفرع غير متاح.',
            self::BranchNotAcceptingOrders => 'الفرع لا يستقبل الطلبات حاليًا.',
            self::BranchTemporarilyClosed => 'الفرع مغلق مؤقتًا.',
            self::BranchClosed => 'الفرع مغلق حاليًا.',
            self::PickupDisabled => 'الاستلام معطل حاليًا.',
            self::DeliveryDisabled => 'التوصيل معطل حاليًا.',
            self::PickupNotSupported => 'الفرع لا يدعم الاستلام.',
            self::DeliveryNotSupported => 'الفرع لا يدعم التوصيل.',
        };
    }
}
