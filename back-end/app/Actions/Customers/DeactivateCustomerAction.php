<?php

namespace App\Actions\Customers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateCustomerAction
{
    public function handle(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->is_active = false;
            $user->save();
            $user->tokens()->delete();

            return $user;
        }, 3);
    }
}
