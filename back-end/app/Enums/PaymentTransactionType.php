<?php

namespace App\Enums;

enum PaymentTransactionType: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Sale = 'sale';
    case Refund = 'refund';
    case Void = 'void';
}
