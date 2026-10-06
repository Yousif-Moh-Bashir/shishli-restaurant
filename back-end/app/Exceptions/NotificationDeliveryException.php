<?php

namespace App\Exceptions;

use App\Enums\NotificationErrorCode;
use RuntimeException;

class NotificationDeliveryException extends RuntimeException
{
    public function __construct(public readonly NotificationErrorCode $errorCode)
    {
        parent::__construct($errorCode->message());
    }
}
