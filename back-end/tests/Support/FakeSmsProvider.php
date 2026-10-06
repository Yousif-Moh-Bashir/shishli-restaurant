<?php

namespace Tests\Support;

use App\Contracts\Notifications\SmsProviderInterface;
use App\Services\Notifications\NotificationProviderResult;
use App\Services\Notifications\SmsMessageData;

class FakeSmsProvider extends FakeNotificationProvider implements SmsProviderInterface
{
    public function send(SmsMessageData $message): NotificationProviderResult
    {
        return $this->next($message);
    }
}
