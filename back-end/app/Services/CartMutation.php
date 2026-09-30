<?php

namespace App\Services;

use App\Actions\Cart\RecalculateCartTotalsAction;
use App\Models\Cart;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class CartMutation
{
    public function __construct(
        private CartResolver $resolver,
        private CartStateService $state,
        private RecalculateCartTotalsAction $totals,
    ) {}

    public function run(Cart $cart, Closure $operation, bool $requiresOrders = true): Cart
    {
        return DB::transaction(function () use ($cart, $operation, $requiresOrders): Cart {
            if ($cart->user_id !== null) {
                User::whereKey($cart->user_id)->lockForUpdate()->firstOrFail();
            }
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->resolver->assertActive($cart);
            $cart->load('branch');
            if ($requiresOrders) {
                $this->state->assertAcceptsOrders($cart->branch);
            }
            $operation($cart);
            $this->totals->handle($cart);

            return $this->state->load($cart);
        }, 3);
    }
}
