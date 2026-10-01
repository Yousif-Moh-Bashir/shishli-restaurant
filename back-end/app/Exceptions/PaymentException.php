<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class PaymentException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $errorCode, public readonly int $statusCode = 422)
    {
        parent::__construct($errorCode);
    }
}
