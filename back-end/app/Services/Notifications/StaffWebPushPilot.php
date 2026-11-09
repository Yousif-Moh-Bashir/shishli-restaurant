<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class StaffWebPushPilot
{
    public function expiresAt(): ?CarbonImmutable
    {
        try {
            $value = config('staff_web_push_pilot.expires_at');

            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)
                ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function enabled(): bool
    {
        $expires = $this->expiresAt();

        return app()->environment('production') && config('staff_web_push_pilot.enabled') === true
            && config('staff_notifications.enabled') !== true
            && config('staff_web_push_pilot.origin') === 'https://shishlifresh.com'
            && Str::isUuid(config('staff_web_push_pilot.user_uuid'))
            && Str::isUuid(config('staff_web_push_pilot.campaign_uuid'))
            && $expires !== null && $expires->isFuture() && $expires->lte(now()->addDay());
    }

    public function ready(): bool
    {
        return $this->enabled() && app(StaffWebPushTransport::class)->ready();
    }

    public function eligible(?User $user, ?PersonalAccessToken $token): bool
    {
        return $this->ready() && $user !== null && $user->uuid === config('staff_web_push_pilot.user_uuid')
            && app(AdminAccessService::class)->allowed($user) && $user->can('orders.view')
            && $token !== null && $token->tokenable_type === User::class && $token->tokenable_id === $user->id
            && ($token->can('*') || $token->can('orders.view'))
            && ($token->expires_at === null || $token->expires_at->isFuture())
            && (! config('sanctum.expiration') || $token->created_at->gt(now()->subMinutes((int) config('sanctum.expiration'))));
    }

    public function allowsRequest(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $this->enabled() && $request->getHttpHost() === 'shishlifresh.com'
            && in_array($request->header('Origin'), [null, 'https://shishlifresh.com'], true)
            && $this->eligible($request->user(), $token instanceof PersonalAccessToken ? $token : null);
    }

    public function key(string $suffix): string
    {
        return 'staff-web-push-pilot:'.config('staff_web_push_pilot.campaign_uuid').':'.$suffix;
    }

    public function record(): ?array
    {
        try {
            $value = Cache::store('redis')->get($this->key('subscription'));

            return is_string($value) ? json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function target(string $id): ?array
    {
        $record = $this->record();
        if (! $this->ready() || $record === null || $record['id'] !== $id
            || $record['vapid_fingerprint'] !== hash('sha256', (string) config('staff_web_push_pilot.vapid.public_key'))
            || ! $this->eligible(User::find($record['user_id']), PersonalAccessToken::find($record['token_id']))) {
            return null;
        }

        return $record;
    }

    public function register(User $user, PersonalAccessToken $token, array $data): array
    {
        abort_unless($this->eligible($user, $token), 403);

        return Cache::store('redis')->lock($this->key('lock'), 10)->block(3, function () use ($user, $token, $data): array {
            $cache = Cache::store('redis');
            $fingerprint = hash('sha256', $data['endpoint']);
            $pinned = $cache->get($this->key('device'));
            abort_if($pinned !== null && $pinned !== $fingerprint, 409);
            $record = $this->record();
            $record = ['id' => $record['id'] ?? (string) Str::uuid(), 'user_id' => $user->id,
                'token_id' => $token->id, 'subscription' => $data,
                'vapid_fingerprint' => hash('sha256', (string) config('staff_web_push_pilot.vapid.public_key'))];
            $expires = $this->expiresAt();
            $cache->put($this->key('device'), $fingerprint, $expires);
            $cache->put($this->key('subscription'), Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), $expires);

            return ['id' => $record['id'], 'expires_at' => $expires->toISOString()];
        });
    }

    public function remove(string $id, PersonalAccessToken $token): void
    {
        Cache::store('redis')->lock($this->key('lock'), 10)->block(3, function () use ($id, $token): void {
            $record = $this->record();
            abort_unless($record !== null && $record['id'] === $id && $record['token_id'] === $token->id, 404);
            Cache::store('redis')->forget($this->key('subscription'));
        });
    }
}
