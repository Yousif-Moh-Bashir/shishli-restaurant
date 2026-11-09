<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Finance\RevenueSource;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Tests\TestCase;

class FinanceHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        if (config('database.default') === 'mysql') {
            $this->assertSame('finance-mysql', config('database.connections.mysql.host'));
            $this->assertSame('shishli_finance_test', config('database.connections.mysql.database'));
            $this->assertEmpty(config('database.connections.mysql.url'));
        } else {
            $this->assertSame('sqlite', config('database.default'));
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        }
    }

    private function prepare(string $role = 'finance_manager'): User
    {
        $this->beforeRefreshingDatabase();
        Http::preventStrayRequests();
        $this->freezeTime();
        $this->seed(FinanceSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Branch::factory()->create();

        return User::factory()->create(['password' => 'HardeningPassword123'])->assignRole($role);
    }

    private function login(User $user, bool $finance = true): string
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');

        return $this->postJson($finance ? '/api/v1/finance/login' : '/api/v1/login', [
            'email' => $user->email, 'password' => 'HardeningPassword123',
        ])->assertOk()->json('data.token');
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_finance_token_expires_after_eight_hours_while_customer_token_lasts_seven_days(): void
    {
        $manager = $this->prepare();
        $financial = $this->login($manager);
        $record = PersonalAccessToken::findToken($financial);
        $this->assertSame(['finance'], $record->abilities);
        $this->assertSame(now()->addHours(8)->toDateTimeString(), $record->expires_at->toDateTimeString());
        $customer = User::factory()->create(['password' => 'HardeningPassword123'])->assignRole('customer');
        $ordinary = $this->login($customer, false);
        $this->assertSame(now()->addDays(7)->toDateTimeString(), PersonalAccessToken::findToken($ordinary)->expires_at->toDateTimeString());
        $this->travel(481)->minutes();
        $this->asToken($financial)->getJson('/api/v1/finance/me')->assertUnauthorized();
        $this->asToken($ordinary)->getJson('/api/user')->assertOk();
    }

    public function test_finance_logout_revokes_only_its_current_token(): void
    {
        $manager = $this->prepare();
        $first = $this->login($manager);
        $second = $this->login($manager);
        $this->asToken($first)->postJson('/api/v1/finance/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->asToken($first)->getJson('/api/v1/finance/me')->assertUnauthorized();
        $this->asToken($second)->getJson('/api/v1/finance/me')->assertOk();
    }

    public static function legacyRoles(): array
    {
        return ['customer' => ['customer'], 'driver' => ['driver'], 'super admin' => ['super_admin'], 'manager' => ['manager']];
    }

    #[DataProvider('legacyRoles')]
    public function test_api_token_name_and_abilities_remain_unchanged_with_role_specific_expiration(string $role): void
    {
        $user = $this->prepare($role);
        $token = $this->login($user, false);
        $record = PersonalAccessToken::findToken($token);
        $this->assertSame('api', $record->name);
        $this->assertSame(['*'], $record->abilities);
        if ($role === 'driver') {
            $this->assertNull($record->expires_at);
        } else {
            $this->assertSame(now()->addMinutes($role === 'customer' ? 10080 : 480)->toDateTimeString(), $record->expires_at->toDateTimeString());
        }
        $this->asToken($token)->getJson('/api/user')->assertOk();
    }

    public function test_generic_token_cannot_bypass_financial_expiration_or_scope(): void
    {
        $manager = $this->prepare();
        $token = $this->login($manager, false);
        $this->asToken($token)->getJson('/api/v1/finance/me')->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_financial_token_cannot_be_used_for_restaurant_admin_even_by_a_shared_admin(): void
    {
        $user = $this->prepare();
        $user->assignRole('super_admin');
        $financial = $this->login($user);
        $this->asToken($financial)->getJson('https://finance.shishlifresh.com/api/v1/finance/me')->assertOk();
        $this->asToken($financial)->getJson('https://shishlifresh.com/api/v1/admin/me')->assertForbidden();
        $this->asToken($financial)->getJson('https://shishlifresh.com/api/user')->assertForbidden();
        $ordinary = $this->login($user, false);
        $this->asToken($ordinary)->getJson('https://shishlifresh.com/api/v1/admin/me')->assertOk();
    }

    public function test_finance_host_and_forwarded_host_cannot_be_used_to_bypass_the_boundary(): void
    {
        $manager = $this->prepare();
        $financial = $this->login($manager);
        $this->asToken($financial)->getJson('https://shishlifresh.com/api/v1/finance/me', [
            'X-Forwarded-Host' => 'finance.shishlifresh.com', 'Forwarded' => 'host=finance.shishlifresh.com;proto=https',
        ])->assertNotFound();
        $this->asToken($financial)->getJson('https://finance.shishlifresh.com.attacker.example/api/v1/finance/me')->assertNotFound();
        $this->asToken($financial)->getJson('https://finance.shishlifresh.com/api/v1/admin/me')->assertNotFound();
    }

    public function test_production_does_not_allow_the_local_development_host(): void
    {
        $manager = $this->prepare();
        $token = $this->login($manager);
        $this->app->instance('env', 'production');
        try {
            $this->asToken($token)->getJson('http://localhost/api/v1/finance/me')->assertNotFound();
            $this->asToken($token)->getJson('https://finance.shishlifresh.com/api/v1/finance/me')->assertOk();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_finance_login_is_not_available_on_customer_host_and_creates_no_token(): void
    {
        $user = $this->prepare();
        $this->postJson('https://shishlifresh.com/api/v1/finance/login', ['email' => $user->email, 'password' => 'HardeningPassword123'])->assertNotFound();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_customer_cannot_obtain_a_financial_token(): void
    {
        $user = $this->prepare('customer');
        $this->postJson('/api/v1/finance/login', ['email' => $user->email, 'password' => 'HardeningPassword123'])->assertForbidden();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_all_finance_error_statuses_keep_security_headers_and_safe_json(): void
    {
        $this->prepare();
        foreach ([401, 403, 500] as $status) {
            Route::get('api/v1/finance/hardening-'.$status, function () use ($status): never {
                abort($status, 'INTERNAL_REVIEW_SECRET');
            });
            $response = $this->getJson('/api/v1/finance/hardening-'.$status)->assertStatus($status);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $this->assertStringNotContainsString('INTERNAL_REVIEW_SECRET', $response->getContent());
        }
    }

    public function test_source_code_cannot_be_changed_by_a_settings_payload_and_custom_codes_are_generated(): void
    {
        $user = $this->prepare();
        $token = $this->login($user);
        $source = RevenueSource::where('code', 'network')->firstOrFail();
        $this->asToken($token)->putJson('/api/v1/finance/settings/sources/'.$source->uuid, [
            'name' => 'اسم شبكة مخصص', 'is_active' => false, 'code' => 'cash',
        ])->assertOk()->assertJsonPath('data.code', 'network');
        $this->seed(FinanceSeeder::class);
        $this->assertSame('اسم شبكة مخصص', $source->fresh()->name);
        $this->assertFalse($source->fresh()->is_active);
        $this->assertDatabaseCount('finance_revenue_sources', 6);
        $result = $this->asToken($token)->postJson('/api/v1/finance/settings/sources', [
            'name' => 'مصدر مخصص', 'is_active' => true, 'code' => 'network',
        ])->assertCreated();
        $this->assertMatchesRegularExpression('/^custom_[a-f0-9]{32}$/', $result->json('data.code'));
    }

    public function test_patched_mime_rejects_header_parameter_injection(): void
    {
        $this->expectException(RfcComplianceException::class);
        new ParameterizedHeader('Content-Disposition', 'attachment', ["filename\r\nBcc" => 'test']);
    }
}
