<?php

namespace Tests\Support;

use App\Contracts\Notifications\EmailProviderInterface;
use App\Services\Notifications\EmailMessageData;
use App\Services\Notifications\NotificationProviderResult;

class FakeEmailProvider extends FakeNotificationProvider implements EmailProviderInterface
{
    public function send(EmailMessageData $message): NotificationProviderResult
    {
        return $this->next($message);
    }
}
