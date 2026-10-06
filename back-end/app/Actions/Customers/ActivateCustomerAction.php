<?php

namespace App\Actions\Customers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivateCustomerAction
{
    public function handle(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->is_active = true;
            $user->save();

            return $user;
        }, 3);
    }
}
