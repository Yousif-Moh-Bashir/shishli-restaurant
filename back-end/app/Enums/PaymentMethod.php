<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Network = 'network';

    public function isOffline(): bool
    {
        return $this === self::Cash || $this === self::Network;
    }
}
