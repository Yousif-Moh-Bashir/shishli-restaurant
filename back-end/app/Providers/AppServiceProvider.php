<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        foreach (config('rate_limiting') as $name => $settings) {
            RateLimiter::for($name, function (Request $request) use ($name, $settings): array {
                $limits = [
                    $this->limit($settings['per_minute'], $this->rateLimitKey($request, $name)),
                ];

                if (isset($settings['ip_per_minute'])) {
                    $limits[] = $this->limit($settings['ip_per_minute'], 'aggregate-ip:'.$request->ip());
                }

                return $limits;
            });
        }
    }

    private function rateLimitKey(Request $request, string $name): string
    {
        if ($name === 'cart') {
            if ($request->user()) {
                return 'user:'.$request->user()->getAuthIdentifier();
            }
            if ($request->hasHeader('X-Cart-Token')) {
                return 'cart:'.hash('sha256', $request->header('X-Cart-Token'));
            }
        }
        if (in_array($name, ['checkout', 'coupons'], true) && $request->user()) {
            return 'user:'.$request->user()->getAuthIdentifier();
        }

        if (in_array($name, ['login', 'otp', 'forgot-password'], true)) {
            $identity = $request->input('email') ?? $request->input('phone');

            if (is_string($identity) && trim($identity) !== '') {
                return 'identity:'.hash('sha256', Str::lower(trim($identity))).'|ip:'.$request->ip();
            }
        }

        return 'ip:'.$request->ip();
    }

    private function limit(int $attempts, string $key): Limit
    {
        return Limit::perMinute($attempts)->by($key);
    }
}
