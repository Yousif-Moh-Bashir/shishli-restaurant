<?php

namespace App\Enums;

enum DeliveryIssueCode: string
{
    case AddressOutsideDeliveryArea = 'ADDRESS_OUTSIDE_DELIVERY_AREA';
    case AddressCoordinatesRequired = 'ADDRESS_COORDINATES_REQUIRED';
    case MinimumOrderNotMet = 'MINIMUM_ORDER_NOT_MET';
    case BranchNotAcceptingOrders = 'BRANCH_NOT_ACCEPTING_ORDERS';
    case DeliveryNotAvailable = 'DELIVERY_NOT_AVAILABLE';
    case InvalidAddress = 'INVALID_ADDRESS';

    public function message(): string
    {
        return match ($this) {
            self::AddressOutsideDeliveryArea => 'العنوان خارج نطاق التوصيل.',
            self::AddressCoordinatesRequired => 'يرجى تحديد إحداثيات العنوان للتحقق من نطاق التوصيل.',
            self::MinimumOrderNotMet => 'لم يتم بلوغ الحد الأدنى للطلب.',
            self::BranchNotAcceptingOrders => 'الفرع لا يستقبل الطلبات حاليًا.',
            self::DeliveryNotAvailable => 'التوصيل غير متاح لهذا الفرع.',
            self::InvalidAddress => 'بيانات العنوان غير صالحة.',
        };
    }
}
