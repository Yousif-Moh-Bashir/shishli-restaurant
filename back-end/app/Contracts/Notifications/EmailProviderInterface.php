<?php

namespace App\Contracts\Notifications;

use App\Services\Notifications\EmailMessageData;
use App\Services\Notifications\NotificationProviderResult;

interface EmailProviderInterface
{
    /** Use the message's stable idempotencyKey whenever the provider supports deduplication. */
    public function send(EmailMessageData $message): NotificationProviderResult;
}
