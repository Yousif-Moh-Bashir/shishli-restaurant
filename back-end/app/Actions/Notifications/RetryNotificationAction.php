<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationStatus;
use App\Models\OutboundNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryNotificationAction
{
    public function __construct(private NotificationDispatcher $dispatcher, private NotificationProviderManager $providers) {}

    public function handle(OutboundNotification $notification): OutboundNotification
    {
        return DB::transaction(function () use ($notification): OutboundNotification {
            $notification = OutboundNotification::whereKey($notification->id)->lockForUpdate()->firstOrFail();
            if ($notification->status !== NotificationStatus::Failed) {
                throw ValidationException::withMessages(['notification' => 'يمكن إعادة محاولة الإشعارات الفاشلة فقط.']);
            }
            $notification->update(['status' => NotificationStatus::Pending, 'failed_at' => null, 'max_attempts' => $notification->attempts + 1,
                'provider' => $this->providers->providerName($notification->channel),
                'metadata' => array_merge($notification->metadata ?? [], ['manual_next_attempt' => true])]);
            $this->dispatcher->dispatch($notification);

            return $notification;
        }, 3);
    }
}
