<?php

namespace App\Contracts\Notifications;

use App\Services\Notifications\NotificationProviderResult;
use App\Services\Notifications\SmsMessageData;

interface SmsProviderInterface
{
    /** Use the message's stable idempotencyKey whenever the provider supports deduplication. */
    public function send(SmsMessageData $message): NotificationProviderResult;
}
