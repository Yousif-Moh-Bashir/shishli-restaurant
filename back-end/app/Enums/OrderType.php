<?php

namespace App\Enums;

enum OrderType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
}
