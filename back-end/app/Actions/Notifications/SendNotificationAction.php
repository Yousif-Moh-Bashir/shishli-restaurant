<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationErrorCode;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationDeliveryException;
use App\Models\OutboundNotification;
use App\Services\Notifications\EmailMessageData;
use App\Services\Notifications\NotificationLogSanitizer;
use App\Services\Notifications\NotificationProviderManager;
use App\Services\Notifications\NotificationProviderResult;
use App\Services\Notifications\PhoneNormalizer;
use App\Services\Notifications\SmsMessageData;
use App\Services\Notifications\WhatsAppMessageData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SendNotificationAction
{
    public function __construct(private NotificationProviderManager $providers, private NotificationLogSanitizer $sanitizer, private PhoneNormalizer $phones) {}

    /** Returns whether a temporary failure should be released for another attempt. */
    public function handle(string $uuid): bool
    {
        $notification = $this->claim($uuid);
        if ($notification === null) {
            return false;
        }
        try {
            $result = $this->send($notification);
        } catch (NotificationDeliveryException $exception) {
            $result = NotificationProviderResult::failure($exception->errorCode);
        } catch (ConnectionException) {
            $result = NotificationProviderResult::failure(NotificationErrorCode::ConnectionFailure);
        } catch (Throwable) {
            $result = NotificationProviderResult::failure(NotificationErrorCode::ProviderException);
        }

        return $this->finish($notification, $result);
    }

    private function claim(string $uuid): ?OutboundNotification
    {
        return DB::transaction(function () use ($uuid): ?OutboundNotification {
            $notification = OutboundNotification::where('uuid', $uuid)->lockForUpdate()->first();
            if ($notification === null || in_array($notification->status, [NotificationStatus::Sent, NotificationStatus::Cancelled, NotificationStatus::Failed], true)) {
                return null;
            }
            if ($notification->status === NotificationStatus::Processing) {
                if ($notification->processing_started_at !== null && $notification->processing_started_at->gt(now()->subSeconds((int) config('outbound_notifications.processing_lease_seconds', 60)))) {
                    return null;
                }
                // Acceptance may have happened remotely before a worker died. Require an explicit manual review/retry.
                $this->failLocked($notification, NotificationErrorCode::WorkerInterrupted);

                return null;
            }
            if (config('outbound_notifications.channels.'.$notification->channel->value.'.enabled') !== true) {
                $notification->update(['status' => NotificationStatus::Cancelled]);

                return null;
            }
            if ($notification->attempts >= $notification->max_attempts) {
                $this->failLocked($notification, NotificationErrorCode::AttemptsExhausted);

                return null;
            }
            $metadata = $notification->metadata ?? [];
            $manual = (bool) ($metadata['manual_next_attempt'] ?? false);
            unset($metadata['manual_next_attempt']);
            $notification->fill(['status' => NotificationStatus::Processing, 'attempts' => $notification->attempts + 1,
                'processing_token' => (string) Str::uuid(), 'processing_started_at' => now(),
                'provider' => $notification->provider ?? $this->providers->providerName($notification->channel),
                'metadata' => $metadata]);
            $notification->save();
            $notification->attemptLogs()->create(['attempt_number' => $notification->attempts, 'status' => NotificationStatus::Processing,
                'provider' => $notification->provider, 'started_at' => now(), 'metadata' => ['manual' => $manual]]);

            return $notification;
        }, 3);
    }

    private function send(OutboundNotification $notification): NotificationProviderResult
    {
        $channel = $notification->channel;
        if (($channel === NotificationChannel::Email && filter_var($notification->recipient, FILTER_VALIDATE_EMAIL) === false)
            || ($channel !== NotificationChannel::Email && $this->phones->normalize($notification->recipient) === null)) {
            return NotificationProviderResult::failure(NotificationErrorCode::InvalidRecipient);
        }
        $provider = $this->providers->resolve($channel, $notification->provider);
        $reference = $notification->reference_uuid ?? $notification->uuid;
        $template = $notification->metadata['template'] ?? [];
        $message = match ($channel) {
            NotificationChannel::Sms => new SmsMessageData($notification->recipient, $notification->content, $reference, $notification->uuid),
            NotificationChannel::Email => new EmailMessageData($notification->recipient, $notification->recipient_name, $notification->subject ?? '', $notification->content, $reference, $notification->uuid),
            NotificationChannel::WhatsApp => new WhatsAppMessageData($notification->recipient, $template['name'] ?? '', $template['language'] ?? 'ar', $template['parameters'] ?? [], $reference, $notification->uuid, $notification->content),
        };
        if ($message instanceof WhatsAppMessageData && ($message->templateName === '' || ! preg_match('/^[a-z0-9_]{1,120}$/D', $message->templateName))) {
            return NotificationProviderResult::failure(NotificationErrorCode::InvalidTemplate);
        }

        return $provider->send($message);
    }

    private function finish(OutboundNotification $claimed, NotificationProviderResult $result): bool
    {
        return DB::transaction(function () use ($claimed, $result): bool {
            $notification = OutboundNotification::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            if ($notification->status !== NotificationStatus::Processing || $notification->processing_token !== $claimed->processing_token) {
                return false;
            }
            $code = $result->success ? null : ($result->errorCode ?? NotificationErrorCode::Rejected);
            $retry = $code?->retryable() === true && $notification->attempts < $notification->max_attempts;
            $safe = $this->sanitizer->metadata($result->metadata);
            $providerId = $this->sanitizer->identifier($result->providerMessageId);
            $attempt = $notification->attemptLogs()->where('attempt_number', $notification->attempts)->firstOrFail();
            $attempt->update(['status' => $result->success ? NotificationStatus::Sent : NotificationStatus::Failed,
                'provider_message_id' => $providerId, 'error_code' => $code?->value, 'error_message' => $code?->message(),
                'finished_at' => now(), 'metadata' => array_merge($attempt->metadata ?? [], $safe, ['retryable' => $code?->retryable() ?? false])]);
            $notification->update(['status' => $result->success ? NotificationStatus::Sent : ($retry ? NotificationStatus::Pending : NotificationStatus::Failed),
                'provider_message_id' => $providerId, 'sent_at' => $result->success ? now() : null,
                'failed_at' => ! $result->success && ! $retry ? now() : null, 'last_error_code' => $code?->value, 'last_error_message' => $code?->message(),
                'processing_token' => null, 'processing_started_at' => null]);
            try {
                Log::info('notification.attempt_finished', ['notification_uuid' => $notification->uuid, 'channel' => $notification->channel->value, 'provider' => $notification->provider, 'attempt' => $notification->attempts, 'status' => $notification->status->value]);
            } catch (Throwable) {
            }

            return $retry;
        }, 3);
    }

    public function queueFailed(string $uuid): void
    {
        DB::transaction(function () use ($uuid): void {
            $notification = OutboundNotification::where('uuid', $uuid)->lockForUpdate()->first();
            if ($notification !== null && ! in_array($notification->status, [NotificationStatus::Sent, NotificationStatus::Cancelled, NotificationStatus::Failed], true)) {
                $this->failLocked($notification, NotificationErrorCode::QueueFailed);
            }
        }, 3);
    }

    private function failLocked(OutboundNotification $notification, NotificationErrorCode $code): void
    {
        $attempt = $notification->attemptLogs()->whereNull('finished_at')->first();
        if ($attempt !== null) {
            $attempt->update(['status' => NotificationStatus::Failed, 'error_code' => $code->value, 'error_message' => $code->message(), 'finished_at' => now()]);
        }
        $notification->update(['status' => NotificationStatus::Failed, 'last_error_code' => $code->value, 'last_error_message' => $code->message(), 'failed_at' => now(), 'processing_token' => null, 'processing_started_at' => null]);
    }
}
