<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\TokenLifetime;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginUserAction
{
    /**
     * @param  array{email?: ?string, phone?: ?string, password: string}  $data
     * @return array{user: User, token: string, expires_at?: ?string, session_only?: bool}
     */
    public function handle(array $data, bool $finance = false): array
    {
        return DB::transaction(function () use ($data, $finance): array {
            $field = isset($data['email']) ? 'email' : 'phone';
            $user = User::where($field, $data[$field])->lockForUpdate()->first();
            if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
                throw new AuthenticationException;
            }

            if ($finance) {
                abort_unless($user->can('finance.access'), 403);

                return ['user' => $user, 'token' => $user->createToken('finance', ['finance'], now()->addMinutes(config('finance.token_minutes')))->plainTextToken];
            }

            $lifetime = app(TokenLifetime::class);
            $minutes = $lifetime->minutes($user);
            $expiresAt = $minutes === null ? null : now()->addMinutes($minutes);

            return [
                'user' => $user,
                'token' => $user->createToken('api', ['*'], $expiresAt)->plainTextToken,
                'expires_at' => $expiresAt?->toISOString(),
                'session_only' => $lifetime->sessionOnly($user),
            ];
        }, 3);
    }
}
