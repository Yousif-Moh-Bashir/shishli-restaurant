<?php

namespace App\Actions\Auth;

use App\Models\StaffPushSubscription;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutUserAction
{
    public function handle(User $user): void
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        DB::transaction(function () use ($user, $token): void {
            StaffPushSubscription::where('user_id', $user->id)
                ->where('personal_access_token_id', $token->id)->delete();
            $token->delete();
        });
    }
}
