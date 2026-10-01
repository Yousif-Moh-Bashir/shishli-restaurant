<?php

namespace App\Enums;

enum OrderStatusSource: string
{
    case System = 'system';
    case Admin = 'admin';
    case Cashier = 'cashier';
    case Kitchen = 'kitchen';
    case Customer = 'customer';
}
