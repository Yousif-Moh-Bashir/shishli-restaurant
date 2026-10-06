<?php

namespace App\Contracts\Notifications;

use App\Services\Notifications\NotificationProviderResult;
use App\Services\Notifications\WhatsAppMessageData;

interface WhatsAppProviderInterface
{
    /** Use the message's stable idempotencyKey whenever the provider supports deduplication. */
    public function send(WhatsAppMessageData $message): NotificationProviderResult;
}
