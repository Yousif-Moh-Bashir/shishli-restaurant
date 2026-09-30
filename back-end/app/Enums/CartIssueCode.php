<?php

namespace App\Enums;

enum CartIssueCode: string
{
    case ProductUnavailable = 'PRODUCT_UNAVAILABLE';
    case ProductNotInBranch = 'PRODUCT_NOT_IN_BRANCH';
    case OptionUnavailable = 'OPTION_UNAVAILABLE';
    case OptionsConfigurationChanged = 'OPTIONS_CONFIGURATION_CHANGED';
    case BranchNotAcceptingOrders = 'BRANCH_NOT_ACCEPTING_ORDERS';
    case PriceLimitExceeded = 'PRICE_LIMIT_EXCEEDED';

    public function issue(?string $message = null): array
    {
        return ['code' => $this->value, 'message' => $message ?? match ($this) {
            self::ProductUnavailable => 'المنتج غير متوفر حاليًا.',
            self::ProductNotInBranch => 'المنتج لم يعد مضافًا إلى هذا الفرع.',
            self::OptionUnavailable => 'أحد الخيارات المحددة لم يعد متاحًا.',
            self::OptionsConfigurationChanged => 'تغيرت إعدادات الخيارات، يرجى مراجعة الاختيارات.',
            self::BranchNotAcceptingOrders => 'الفرع لا يستقبل الطلبات حاليًا.',
            self::PriceLimitExceeded => 'إجمالي السعر يتجاوز الحد المسموح للسلة.',
        }];
    }
}
