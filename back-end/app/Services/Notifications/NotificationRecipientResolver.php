<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;

class NotificationRecipientResolver
{
    public function __construct(private PhoneNormalizer $phones) {}

    /** Missing recipients are skipped; existing invalid recipients retain a failed snapshot. @return array{recipient:string,valid:bool}|null */
    public function resolve(NotificationContext $context, NotificationChannel $channel): ?array
    {
        $raw = $channel === NotificationChannel::Email ? $context->email : $context->phone;
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if ($channel === NotificationChannel::Email) {
            return ['recipient' => $raw, 'valid' => strlen($raw) <= 254 && filter_var($raw, FILTER_VALIDATE_EMAIL) !== false];
        }
        $normalized = $this->phones->normalize($raw, config('outbound_notifications.phone_country'));

        return ['recipient' => $normalized ?? $raw, 'valid' => $normalized !== null];
    }
}
