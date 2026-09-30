<?php

namespace App\Actions\Cart;

use App\Enums\CartStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Services\CartMutation;
use App\Services\CartStateService;
use Illuminate\Validation\ValidationException;

class ChangeCartBranchAction
{
    public function __construct(private CartMutation $mutation, private CartStateService $state) {}

    public function handle(Cart $cart, string $branchUuid): Cart
    {
        return $this->mutation->run($cart, function (Cart $cart) use ($branchUuid): void {
            if ($cart->items()->exists()) {
                throw ValidationException::withMessages(['branch_uuid' => 'لا يمكن تغيير الفرع لسلة تحتوي على منتجات.']);
            }
            $branch = Branch::where('uuid', $branchUuid)->lockForUpdate()->firstOrFail();
            $this->state->assertAcceptsOrders($branch);
            if ($cart->user_id !== null) {
                $existing = Cart::where('user_id', $cart->user_id)->where('branch_id', $branch->id)
                    ->where('status', CartStatus::Active)->whereKeyNot($cart->id)->lockForUpdate()->first();
                if ($existing !== null && ($existing->expires_at === null || $existing->expires_at->isFuture())) {
                    throw ValidationException::withMessages(['branch_uuid' => 'لديك سلة نشطة لهذا الفرع بالفعل.']);
                }
                $existing?->update(['status' => CartStatus::Abandoned]);
            }
            $cart->update(['branch_id' => $branch->id]);
        }, requiresOrders: false);
    }
}
