<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    #[DataProvider('limits')]
    public function test_returns_429_with_retry_headers_and_allows_requests_after_one_minute(string $limiter, int $attempts): void
    {
        $this->freezeTime();
        $path = $this->registerLimitedRoute($limiter);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->postJson($path, ['email' => 'person@example.com'])->assertNoContent();
        }

        $this->postJson($path, ['email' => 'person@example.com'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertExactJson([
                'success' => false,
                'message' => 'تم تجاوز عدد المحاولات المسموح بها، يرجى المحاولة لاحقًا',
            ]);

        $this->travel(61)->seconds();
        $this->postJson($path, ['email' => 'person@example.com'])->assertNoContent();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function limits(): array
    {
        return [
            'login' => ['login', 5],
            'register' => ['register', 6],
            'otp' => ['otp', 5],
            'forgot password' => ['forgot-password', 3],
            'checkout' => ['checkout', 10],
            'coupons' => ['coupons', 5],
        ];
    }

    #[DataProvider('identityLimits')]
    public function test_ip_limit_returns_429_even_when_account_identifiers_change(string $limiter, int $ipAttempts): void
    {
        $path = $this->registerLimitedRoute($limiter);

        for ($attempt = 0; $attempt < $ipAttempts; $attempt++) {
            $this->postJson($path, ['email' => 'person'.$attempt.'@example.com'])->assertNoContent();
        }

        $this->postJson($path, ['email' => 'another@example.com'])->assertTooManyRequests();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function identityLimits(): array
    {
        return [
            'login' => ['login', 30],
            'otp' => ['otp', 15],
            'forgot password' => ['forgot-password', 10],
        ];
    }

    public function test_login_normalizes_email_and_keeps_other_accounts_and_ips_independent(): void
    {
        $path = $this->registerLimitedRoute('login');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($path, ['email' => 'Person@example.com'])->assertNoContent();
        }

        $this->postJson($path, ['email' => ' person@EXAMPLE.COM '])->assertTooManyRequests();
        $this->postJson($path, ['email' => 'other@example.com'])->assertNoContent();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson($path, ['email' => 'person@example.com'])->assertNoContent();
    }

    public function test_invalid_identifier_types_are_limited_by_ip_without_server_errors(): void
    {
        $path = $this->registerLimitedRoute('login');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($path, ['email' => ['invalid']])->assertNoContent();
        }

        $this->postJson($path, ['email' => ['invalid']])->assertTooManyRequests();
    }

    public function test_otp_limits_phone_attempts(): void
    {
        $path = $this->registerLimitedRoute('otp');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($path, ['phone' => '+249123456789'])->assertNoContent();
        }

        $this->postJson($path, ['phone' => '+249123456789'])->assertTooManyRequests();
    }

    public function test_authenticated_checkout_limit_follows_user_across_ips_and_does_not_block_other_users(): void
    {
        $path = $this->registerLimitedRoute('checkout');
        $this->actingAs(User::factory()->make(['id' => 1]));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson($path)->assertNoContent();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])
            ->postJson($path)->assertTooManyRequests();
        $this->actingAs(User::factory()->make(['id' => 2]))
            ->postJson($path)->assertNoContent();
    }

    public function test_exhausting_one_operation_does_not_block_another(): void
    {
        $login = $this->registerLimitedRoute('login');
        $coupons = $this->registerLimitedRoute('coupons');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($login)->assertNoContent();
        }

        $this->postJson($login)->assertTooManyRequests();
        $this->postJson($coupons)->assertNoContent();
    }

    private function registerLimitedRoute(string $limiter): string
    {
        $path = '/api/test-limits/'.$limiter;
        Route::middleware(['api', 'throttle:'.$limiter])
            ->post($path, fn () => response()->noContent());

        return $path;
    }
}
