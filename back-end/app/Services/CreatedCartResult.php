<?php

namespace App\Services;

use App\Models\Cart;

final readonly class CreatedCartResult
{
    public function __construct(public Cart $cart, public ?string $token, public bool $created) {}
}
