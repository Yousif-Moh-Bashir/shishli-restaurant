<?php

namespace App\Services\Notifications;

readonly class EmailMessageData
{
    public function __construct(public string $recipient, public ?string $recipientName, public string $subject, public string $body, public string $reference, public string $idempotencyKey) {}
}
