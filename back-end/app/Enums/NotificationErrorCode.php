<?php

namespace App\Enums;

enum NotificationErrorCode: string
{
    case ProviderNotConfigured = 'PROVIDER_NOT_CONFIGURED';
    case InvalidRecipient = 'INVALID_RECIPIENT';
    case InvalidTemplate = 'INVALID_TEMPLATE';
    case Unauthorized = 'PROVIDER_UNAUTHORIZED';
    case Rejected = 'PROVIDER_REJECTED';
    case RateLimited = 'PROVIDER_RATE_LIMITED';
    case Unavailable = 'PROVIDER_UNAVAILABLE';
    case ConnectionFailure = 'CONNECTION_FAILURE';
    case Timeout = 'TIMEOUT';
    case ProviderException = 'PROVIDER_EXCEPTION';
    case QueueDispatchFailed = 'QUEUE_DISPATCH_FAILED';
    case QueueFailed = 'QUEUE_FAILED';
    case WorkerInterrupted = 'WORKER_INTERRUPTED';
    case AttemptsExhausted = 'ATTEMPTS_EXHAUSTED';

    public function retryable(): bool
    {
        return in_array($this, [self::RateLimited, self::Unavailable, self::ConnectionFailure, self::Timeout], true);
    }

    public function message(): string
    {
        return match ($this) {
            self::ProviderNotConfigured => 'مزود الإشعارات غير مهيأ.',
            self::InvalidRecipient => 'بيانات المستلم غير صالحة.',
            self::InvalidTemplate => 'قالب الإرسال غير مهيأ أو غير صالح.',
            self::Unauthorized => 'رفض المزود إعدادات المصادقة.',
            self::Rejected => 'رفض المزود الرسالة.',
            self::RateLimited => 'تم تجاوز معدل إرسال المزود.',
            self::Unavailable => 'مزود الإرسال غير متاح مؤقتًا.',
            self::ConnectionFailure => 'تعذر الاتصال بمزود الإرسال.',
            self::Timeout => 'انتهت مهلة الاتصال بمزود الإرسال.',
            self::ProviderException => 'تعذر تنفيذ الإرسال لدى المزود.',
            self::QueueDispatchFailed => 'تعذر إدراج مهمة الإرسال في الطابور.',
            self::QueueFailed => 'فشلت مهمة الإرسال.',
            self::WorkerInterrupted => 'توقفت محاولة الإرسال قبل تسجيل نتيجتها؛ يلزم مراجعتها.',
            self::AttemptsExhausted => 'تم استنفاد محاولات الإرسال.',
        };
    }

    public static function fromHttpStatus(int $status): self
    {
        return match (true) {
            $status === 429 => self::RateLimited,$status >= 500 => self::Unavailable,in_array($status, [401, 403], true) => self::Unauthorized,default => self::Rejected
        };
    }
}
