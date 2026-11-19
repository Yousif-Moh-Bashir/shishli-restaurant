<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\OrderType;

class NotificationTemplateRenderer
{
    public function render(NotificationType $type, NotificationChannel $channel, NotificationContext $context): RenderedNotification
    {
        $label = match ($type) {
            NotificationType::OrderPlaced => 'تم استلام طلبك للمراجعة؛ الإرسال لا يعني قبول المطعم',
            NotificationType::OrderConfirmed => 'قبِل المطعم طلبك',
            NotificationType::OrderPreparing => 'بدأ فريق شيشلي تجهيز طلبك',
            NotificationType::OrderReady => $context->orderType === OrderType::Pickup ? 'طلبك جاهز للاستلام' : 'تم تجهيز طلبك',
            NotificationType::OrderOutForDelivery => 'طلبك خرج للتوصيل',
            NotificationType::OrderCompleted => 'اكتمل طلبك، شكرًا لاختيارك شيشلي',
            NotificationType::OrderCancelled => 'تم إلغاء طلبك',
            NotificationType::PaymentSucceeded => 'تم استلام دفعة طلبك بنجاح',
            NotificationType::PaymentFailed => 'تعذر إتمام دفعة طلبك؛ يمكنك مراجعة طريقة الدفع',
        };
        $content = $label.' رقم '.$context->orderNumber.'.';
        $template = config('outbound_notifications.whatsapp_templates.'.$type->value, []);

        return new RenderedNotification($channel === NotificationChannel::Email ? 'شيشلي — طلب '.$context->orderNumber : null, $content,
            $channel === NotificationChannel::WhatsApp ? ($template['name'] ?? null) : null,
            $template['language'] ?? 'ar', [$context->customerName, $context->orderNumber, $label]);
    }
}
