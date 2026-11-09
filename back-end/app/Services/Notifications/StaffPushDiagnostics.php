<?php

namespace App\Services\Notifications;

use App\Models\StaffPushSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class StaffPushDiagnostics
{
    private const PREFIX = 'staff-web-push-diagnostic:';

    public function enabled(): bool
    {
        $origin = (string) config('staff_notifications.push_diagnostics.origin');
        $host = parse_url($origin, PHP_URL_HOST);
        $local = is_string($host) && ($host === 'localhost' || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost') || (filter_var($host, FILTER_VALIDATE_IP)
                && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)));

        return app()->environment('local') && config('staff_notifications.push_diagnostics.enabled') === true
            && config('staff_notifications.enabled') !== true && $local
            && parse_url($origin, PHP_URL_SCHEME) === 'https'
            && ! parse_url($origin, PHP_URL_USER) && ! parse_url($origin, PHP_URL_PASS)
            && ! parse_url($origin, PHP_URL_QUERY) && ! parse_url($origin, PHP_URL_FRAGMENT)
            && in_array(parse_url($origin, PHP_URL_PATH), [null, '', '/'], true);
    }

    public function allowsRequest(Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $origin = rtrim((string) config('staff_notifications.push_diagnostics.origin'), '/');
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $authority = $host.($port && $port !== 443 ? ':'.$port : '');
        $header = $request->header('Origin');

        return strtolower($request->getHttpHost()) === strtolower($authority)
            && ($header === null || rtrim($header, '/') === $origin);
    }

    public function ready(): bool
    {
        return $this->enabled() && app(StaffWebPushTransport::class)->ready();
    }

    /** @return array<string,mixed> */
    public function capabilities(Request $request): array
    {
        if (! $this->allowsRequest($request)) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true, 'ready' => $this->ready(),
            'public_key' => $this->ready() ? config('staff_notifications.push.public_key') : null,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function register(User $user, PersonalAccessToken $token, array $data): array
    {
        abort_unless($this->ready() && app(StaffOrderNotifications::class)->eligible($user), 403);
        $hash = hash('sha256', $data['endpoint']);
        $cache = Cache::store('redis');

        return $cache->lock(self::PREFIX.'lock:'.$hash, 10)->block(3, function () use ($user, $token, $data, $hash, $cache): array {
            $existingId = $cache->get(self::PREFIX.'endpoint:'.$hash);
            $existing = is_string($existingId) ? $this->read($existingId) : null;
            abort_if($existing !== null && $existing['user_id'] !== $user->id, 409);
            $id = $existing['id'] ?? (string) Str::uuid();
            $seconds = min(28800, max(60, (int) config('staff_notifications.push_diagnostics.subscription_seconds', 28800)));
            $record = [
                'id' => $id, 'user_id' => $user->id, 'user_uuid' => $user->uuid, 'token_id' => $token->id,
                'subscription' => $data, 'endpoint_hash' => $hash,
                'vapid_fingerprint' => hash('sha256', (string) config('staff_notifications.push.public_key')),
                'expires_at' => now()->addSeconds($seconds)->toISOString(),
            ];
            $sessionKey = self::PREFIX.'session:'.$token->id;
            $previous = $cache->get($sessionKey);
            if (is_string($previous) && $previous !== $id) {
                $this->remove($previous, $user->id);
            }
            $cache->put(self::PREFIX.'subscription:'.$id, Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), $seconds);
            $cache->put(self::PREFIX.'endpoint:'.$hash, $id, $seconds);
            $cache->put($sessionKey, $id, $seconds);

            return ['id' => $id, 'user_id' => $user->uuid, 'expires_at' => $record['expires_at']];
        });
    }

    /** @return array<string,mixed>|null */
    public function read(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        try {
            $value = Cache::store('redis')->get(self::PREFIX.'subscription:'.$id);

            return is_string($value) ? json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public function target(string $id, string $userUuid): ?array
    {
        if (! $this->ready()) {
            return null;
        }
        $record = $this->read($id);
        if ($record === null || $record['user_uuid'] !== $userUuid
            || $record['vapid_fingerprint'] !== hash('sha256', (string) config('staff_notifications.push.public_key'))) {
            return null;
        }
        $token = PersonalAccessToken::find($record['token_id']);
        $user = User::find($record['user_id']);
        $subscription = new StaffPushSubscription(['user_id' => $record['user_id']]);
        $subscription->setRelation('user', $user);
        $subscription->setRelation('accessToken', $token);

        return app(StaffOrderNotifications::class)->subscriptionEligible($subscription) ? $record : null;
    }

    /** @return array<string,mixed>|null */
    public function forSession(User $user, PersonalAccessToken $token): ?array
    {
        $id = Cache::store('redis')->get(self::PREFIX.'session:'.$token->id);
        $record = is_string($id) ? $this->target($id, $user->uuid) : null;
        if ($record === null || $record['token_id'] !== $token->id) {
            return null;
        }

        return ['id' => $record['id'], 'user_id' => $user->uuid, 'expires_at' => $record['expires_at']];
    }

    public function remove(string $id, int $userId): void
    {
        $record = $this->read($id);
        if ($record === null || $record['user_id'] !== $userId) {
            return;
        }
        $cache = Cache::store('redis');
        $cache->forget(self::PREFIX.'subscription:'.$id);
        if ($cache->get(self::PREFIX.'endpoint:'.$record['endpoint_hash']) === $id) {
            $cache->forget(self::PREFIX.'endpoint:'.$record['endpoint_hash']);
        }
        if ($cache->get(self::PREFIX.'session:'.$record['token_id']) === $id) {
            $cache->forget(self::PREFIX.'session:'.$record['token_id']);
        }
    }
}
