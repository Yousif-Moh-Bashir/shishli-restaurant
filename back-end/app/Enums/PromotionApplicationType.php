<?php

namespace App\Enums;

enum PromotionApplicationType: string
{
    case Cart = 'cart';
    case Product = 'product';
    case Category = 'category';
}
