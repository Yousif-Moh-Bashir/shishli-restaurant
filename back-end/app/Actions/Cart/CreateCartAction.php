<?php

namespace App\Actions\Cart;

use App\Enums\CartStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\User;
use App\Services\CartStateService;
use App\Services\CreatedCartResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCartAction
{
    public function __construct(private CartStateService $state) {}

    public function handle(string $branchUuid, ?User $user): CreatedCartResult
    {
        return DB::transaction(function () use ($branchUuid, $user): CreatedCartResult {
            if ($user !== null) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            }
            $branch = Branch::where('uuid', $branchUuid)->lockForUpdate()->first();
            if ($branch === null) {
                throw ValidationException::withMessages(['branch_uuid' => 'الفرع المحدد غير موجود.']);
            }
            $this->state->assertAcceptsOrders($branch);
            if ($user !== null) {
                $existing = Cart::where('user_id', $user->id)->where('branch_id', $branch->id)->where('status', CartStatus::Active)->first();
                if ($existing !== null && ($existing->expires_at === null || $existing->expires_at->isFuture())) {
                    return new CreatedCartResult($this->state->load($existing), null, false);
                }
                $existing?->update(['status' => CartStatus::Abandoned]);
            }
            $token = Str::random(64);
            $cart = new Cart(['user_id' => $user?->id, 'branch_id' => $branch->id,
                'status' => CartStatus::Active, 'expires_at' => $user === null ? now()->addDays(7) : null]);
            $cart->token = hash('sha256', $token);
            $cart->save();

            return new CreatedCartResult($this->state->load($cart->refresh()), $user === null ? $token : null, true);
        }, 3);
    }
}
