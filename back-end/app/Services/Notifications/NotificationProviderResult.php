<?php

namespace App\Services\Notifications;

use App\Enums\NotificationErrorCode;

readonly class NotificationProviderResult
{
    public function __construct(public bool $success, public ?string $providerMessageId = null, public ?string $providerReference = null, public ?NotificationErrorCode $errorCode = null, public ?string $safeErrorMessage = null, public array $metadata = []) {}

    public static function failure(NotificationErrorCode $code): self
    {
        return new self(false, errorCode: $code, safeErrorMessage: $code->message());
    }

    public static function fromHttpStatus(int $status): self
    {
        return self::failure(NotificationErrorCode::fromHttpStatus($status));
    }
}
