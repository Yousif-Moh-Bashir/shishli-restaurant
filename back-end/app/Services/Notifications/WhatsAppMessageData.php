<?php

namespace App\Services\Notifications;

readonly class WhatsAppMessageData
{
    /** @param list<string> $parameters */
    public function __construct(public string $recipient, public string $templateName, public string $languageCode, public array $parameters, public string $reference, public string $idempotencyKey, public ?string $content = null) {}
}
