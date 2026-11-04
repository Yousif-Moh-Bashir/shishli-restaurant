<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginUserAction
{
    /**
     * @param  array{email?: ?string, phone?: ?string, password: string}  $data
     * @return array{user: User, token: string}
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

            return ['user' => $user, 'token' => $user->createToken('api')->plainTextToken];
        }, 3);
    }
}
