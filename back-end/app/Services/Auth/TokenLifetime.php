<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Laravel\Sanctum\PersonalAccessToken;

class TokenLifetime
{
    public function sessionOnly(User $user): bool
    {
        return app(AdminAccessService::class)->allowed($user);
    }

    public function minutes(User $user): ?int
    {
        if ($this->sessionOnly($user)) {
            return (int) config('auth.token_lifetimes.admin_minutes');
        }

        return $user->hasRole('driver') ? null : (int) config('auth.token_lifetimes.customer_minutes');
    }

    public function valid(PersonalAccessToken $token, bool $valid): bool
    {
        if (! $valid || $token->name !== 'api' || ! $token->tokenable instanceof User) {
            return $valid;
        }
        $minutes = $this->minutes($token->tokenable);

        return $minutes === null || ($token->created_at !== null && $token->created_at->copy()->addMinutes($minutes)->isFuture());
    }
}
