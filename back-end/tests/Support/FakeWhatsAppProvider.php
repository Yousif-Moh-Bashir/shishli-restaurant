<?php

namespace Tests\Support;

use App\Contracts\Notifications\WhatsAppProviderInterface;
use App\Services\Notifications\NotificationProviderResult;
use App\Services\Notifications\WhatsAppMessageData;

class FakeWhatsAppProvider extends FakeNotificationProvider implements WhatsAppProviderInterface
{
    public function send(WhatsAppMessageData $message): NotificationProviderResult
    {
        return $this->next($message);
    }
}
