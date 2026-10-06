<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\PaymentSucceeded;
use App\Services\Notifications\NotificationService;

class QueuePaymentSucceededNotifications
{
    public function __construct(private NotificationService $notifications) {}

    public function handle(PaymentSucceeded $event): void
    {
        $this->notifications->queuePayment(NotificationType::PaymentSucceeded, $event->payment, $event->order);
    }
}
