<?php

namespace App\Enums;

enum PromotionIssueCode: string
{
    case NotFound = 'COUPON_NOT_FOUND';
    case Inactive = 'COUPON_INACTIVE';
    case NotStarted = 'COUPON_NOT_STARTED';
    case Expired = 'COUPON_EXPIRED';
    case UsageLimitReached = 'COUPON_USAGE_LIMIT_REACHED';
    case UserLimitReached = 'COUPON_USER_LIMIT_REACHED';
    case WrongBranch = 'COUPON_NOT_AVAILABLE_FOR_BRANCH';
    case MinimumNotMet = 'COUPON_MINIMUM_NOT_MET';
    case NotApplicable = 'COUPON_NOT_APPLICABLE';
    case AuthenticationRequired = 'COUPON_AUTHENTICATION_REQUIRED';

    public function issue(): array
    {
        return ['code' => $this->value, 'message' => match ($this) {
            self::NotFound => 'الكوبون غير موجود.',
            self::Inactive => 'الكوبون أو العرض غير نشط.',
            self::NotStarted => 'لم تبدأ صلاحية الكوبون أو العرض.',
            self::Expired => 'انتهت صلاحية الكوبون أو العرض.',
            self::UsageLimitReached => 'تم استنفاد عدد الاستخدامات المتاحة.',
            self::UserLimitReached => 'وصلت إلى حد الاستخدام المسموح.',
            self::WrongBranch => 'الكوبون غير متاح لهذا الفرع.',
            self::MinimumNotMet => 'لم تصل السلة إلى الحد الأدنى للعرض.',
            self::NotApplicable => 'الكوبون لا ينطبق على السلة أو تمنع قواعد الجمع تطبيقه.',
            self::AuthenticationRequired => 'يلزم تسجيل الدخول لاستخدام هذا الكوبون.',
        }];
    }
}
