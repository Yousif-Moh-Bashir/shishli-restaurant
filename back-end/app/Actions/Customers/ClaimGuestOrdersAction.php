<?php

namespace App\Actions\Customers;

use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

class ClaimGuestOrdersAction
{
    public function __construct(private PhoneNormalizer $phones) {}

    public function handle(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->phone_verified_at !== null, 403);
            $phone = $user->phone === null ? null : $this->phones->normalize($user->phone, config('outbound_notifications.phone_country'));
            abort_unless($phone !== null && $user->phone_normalized === $phone, 403);

            // The conditional update acquires write locks and cannot overwrite an existing owner.
            return Order::query()->whereNull('user_id')->where('customer_phone_normalized', $phone)->update([
                'user_id' => $user->id, 'guest_access_token_hash' => null, 'guest_access_token_expires_at' => null,
            ]);
        }, 3);
    }
}
