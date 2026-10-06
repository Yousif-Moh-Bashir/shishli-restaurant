<?php

namespace App\Services\Notifications;

readonly class SmsMessageData
{
    public function __construct(public string $recipient, public string $content, public string $reference, public string $idempotencyKey) {}
}
