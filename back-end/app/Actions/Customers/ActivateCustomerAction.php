<?php

namespace App\Actions\Customers;

use App\Models\User;
use App\Services\Customers\CustomerLookupService;
use Illuminate\Support\Facades\DB;

class ActivateCustomerAction
{
    public function handle(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user = app(CustomerLookupService::class)->customers()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->is_active = true;
            $user->save();

            return $user;
        }, 3);
    }
}
