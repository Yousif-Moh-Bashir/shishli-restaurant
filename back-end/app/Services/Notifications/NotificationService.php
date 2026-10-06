<?php

namespace App\Services\Notifications;

use App\Enums\NotificationErrorCode;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OutboundNotification;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    public function queueOrder(NotificationType $type, Order $order, ?string $paymentUuid = null): void
    {
        try {
            $this->queueNotification($type, NotificationContext::fromOrder($order, $paymentUuid));
        } catch (Throwable) {
            try {
                Log::error('notification.context_failed', ['reference_uuid' => $paymentUuid ?? $order->uuid, 'type' => $type->value]);
            } catch (Throwable) {
            }
        }
    }

    public function queuePayment(NotificationType $type, Payment $payment, ?Order $order = null): void
    {
        try {
            if ($this->channels->resolve($type) === []) {
                return;
            }
            $this->queueOrder($type, $order ?? $payment->order, $payment->uuid);
        } catch (Throwable) {
            try {
                Log::error('notification.context_failed', ['reference_uuid' => $payment->uuid, 'type' => $type->value]);
            } catch (Throwable) {
            }
        }
    }

    public function __construct(private NotificationChannelResolver $channels, private NotificationRecipientResolver $recipients, private NotificationTemplateRenderer $templates, private NotificationProviderManager $providers, private NotificationDispatcher $dispatcher) {}

    /** Event-side errors are contained per channel so business state remains committed. @return list<OutboundNotification> */
    public function queueNotification(NotificationType $type, NotificationContext $context): array
    {
        $created = [];
        try {
            $channels = $this->channels->resolve($type);
        } catch (Throwable) {
            $this->logFailure($type, $context);

            return [];
        }
        if ($type === NotificationType::OrderOutForDelivery && $context->orderType !== OrderType::Delivery) {
            return [];
        }
        foreach ($channels as $channel) {
            try {
                $recipient = $this->recipients->resolve($context, $channel);
                if ($recipient === null) {
                    continue;
                }
                $rendered = $this->templates->render($type, $channel, $context);
                $key = $context->referenceType.':'.$context->referenceUuid.':'.$type->value.':'.$channel->value;
                $notification = OutboundNotification::firstOrCreate(['deduplication_key' => $key], [
                    'type' => $type, 'channel' => $channel, 'status' => $recipient['valid'] ? NotificationStatus::Pending : NotificationStatus::Failed,
                    'recipient' => mb_substr($recipient['recipient'], 0, 254), 'recipient_name' => $context->customerName,
                    'subject' => $rendered->subject, 'content' => $rendered->content,
                    'provider' => $this->providers->providerName($channel),
                    'reference_type' => $context->referenceType, 'reference_uuid' => $context->referenceUuid,
                    'max_attempts' => max(1, (int) config('outbound_notifications.max_attempts', 3)),
                    'failed_at' => $recipient['valid'] ? null : now(),
                    'last_error_code' => $recipient['valid'] ? null : NotificationErrorCode::InvalidRecipient->value,
                    'last_error_message' => $recipient['valid'] ? null : NotificationErrorCode::InvalidRecipient->message(),
                    'metadata' => ['template' => ['name' => $rendered->templateName, 'language' => $rendered->languageCode, 'parameters' => $rendered->parameters]],
                ]);
                $created[] = $notification;
                if ($notification->wasRecentlyCreated && $notification->status === NotificationStatus::Pending) {
                    $this->dispatcher->dispatch($notification);
                }
            } catch (Throwable) {
                $this->logFailure($type, $context, $channel->value);
            }
        }

        return $created;
    }

    private function logFailure(NotificationType $type, NotificationContext $context, ?string $channel = null): void
    {
        try {
            Log::error('notification.creation_failed', ['type' => $type->value, 'reference_uuid' => $context->referenceUuid, 'channel' => $channel]);
        } catch (Throwable) {
        }
    }
}
