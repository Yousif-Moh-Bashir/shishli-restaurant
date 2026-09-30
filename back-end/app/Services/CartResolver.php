<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Models\Cart;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartResolver
{
    public function resolve(Request $request): Cart
    {
        $user = $request->user();
        $token = $request->header('X-Cart-Token');
        $query = Cart::query();
        if ($token !== null) {
            abort_unless(is_string($token) && strlen($token) === 64, 404);
            $query->where('token', hash('sha256', $token));
            $user ? $query->where('user_id', $user->id) : $query->whereNull('user_id');
        } elseif ($user !== null) {
            $query->where('user_id', $user->id);
            if ($request->hasHeader('X-Cart-UUID')) {
                $query->where('uuid', $request->header('X-Cart-UUID'));
            } else {
                $query->where('status', CartStatus::Active)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                if ((clone $query)->count() > 1) {
                    throw ValidationException::withMessages(['cart' => 'يرجى تحديد السلة عبر X-Cart-UUID عند وجود سلات لأكثر من فرع.']);
                }
            }
        } else {
            throw new AuthenticationException;
        }
        $cart = $query->firstOrFail();
        $this->assertActive($cart);

        return $cart;
    }

    public function assertActive(Cart $cart): void
    {
        abort_unless($cart->status === CartStatus::Active && ($cart->expires_at === null || $cart->expires_at->isFuture()), 404);
    }
}
