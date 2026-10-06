<?php

namespace App\Actions\Customers;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateCustomerProfileAction
{
    public function handle(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active, 401);
            $user->fill(Arr::only($data, ['name', 'email']));
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();

            return $user;
        }, 3);
    }
}
