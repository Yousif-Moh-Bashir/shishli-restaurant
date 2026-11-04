<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Finance\ExpenseCategory;
use App\Models\Finance\RevenueSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Fruitcake\Cors\CorsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceOperationalReviewTest extends TestCase
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

    private function prepare(): Branch
    {
        $this->beforeRefreshingDatabase();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-02T08:00:00Z'));
        $this->seed(FinanceSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        return Branch::factory()->create();
    }

    private function login(string $role, Branch $branch): string
    {
        $user = User::factory()->create(['password' => 'LocalReviewPassword123'])->assignRole($role);
        DB::table('finance_branch_user')->insert(['user_id' => $user->id, 'branch_id' => $branch->id]);
        $this->app['auth']->forgetGuards();
        $response = $this->postJson($role === 'customer' ? '/api/v1/login' : '/api/v1/finance/login', ['email' => $user->email, 'password' => 'LocalReviewPassword123'])->assertOk();
        $token = $response->json('data.token');
        $this->assertIsString($token);

        return $token;
    }

    private function requestWithToken(string $method, string $uri, string $token, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        if (isset($headers['Host'])) {
            $uri = 'https://'.$headers['Host'].$uri;
        }

        return $this->json($method, $uri, $data, ['Authorization' => 'Bearer '.$token, ...$headers]);
    }

    public function test_real_bearer_login_and_complete_financial_workflow_then_logout(): void
    {
        $branch = $this->prepare();
        $token = $this->login('finance_manager', $branch);
        $this->requestWithToken('GET', '/api/v1/finance/me', $token)->assertOk();
        $data = ['branch_uuid' => $branch->uuid, 'date' => '2026-11-02', 'total' => '9999.99', 'status' => 'approved', 'lines' => [
            ['source_uuid' => RevenueSource::firstOrFail()->uuid, 'amount' => '0.10'],
            ['source_uuid' => RevenueSource::orderBy('id')->skip(1)->firstOrFail()->uuid, 'amount' => '0.20'],
        ]];
        $uuid = $this->requestWithToken('POST', '/api/v1/finance/revenues', $token, $data)->assertCreated()->assertJsonPath('data.total', '0.30')->assertJsonPath('data.status', 'draft')->json('data.uuid');
        $this->requestWithToken('POST', '/api/v1/finance/revenues', $token, $data)->assertUnprocessable();
        $this->requestWithToken('POST', "/api/v1/finance/revenues/$uuid/approve", $token, ['version' => 1])->assertOk();
        $this->requestWithToken('POST', "/api/v1/finance/revenues/$uuid/approve", $token, ['version' => 2])->assertConflict();
        $this->requestWithToken('PUT', "/api/v1/finance/revenues/$uuid", $token, [...$data, 'version' => 2])->assertConflict();
        $data['lines'][0]['amount'] = '100.10';
        $this->requestWithToken('POST', "/api/v1/finance/revenues/$uuid/correct", $token, [...$data, 'version' => 2, 'reason' => 'تصحيح موثق في المراجعة التشغيلية'])->assertOk()->assertJsonPath('data.total', '100.30');
        $this->assertDatabaseHas('finance_audit_logs', ['action' => 'revenues.correct', 'subject_uuid' => $uuid, 'reason' => 'تصحيح موثق في المراجعة التشغيلية']);
        $supplier = $this->requestWithToken('POST', '/api/v1/finance/settings/suppliers', $token, ['name' => 'مورد مراجعة', 'is_active' => true])->assertCreated()->json('data.uuid');
        $outflow = ['branch_uuid' => $branch->uuid, 'date' => '2026-11-02', 'description' => 'مراجعة محلية معزولة', 'payment_method' => 'transfer'];
        $this->requestWithToken('POST', '/api/v1/finance/expenses', $token, [...$outflow, 'amount' => '20.25', 'category_uuid' => ExpenseCategory::firstOrFail()->uuid])->assertCreated();
        $purchase = [...$outflow, 'amount' => '30.10', 'supplier_uuid' => $supplier, 'invoice_number' => 'REVIEW-001'];
        $this->requestWithToken('POST', '/api/v1/finance/purchases', $token, $purchase)->assertCreated();
        $this->requestWithToken('POST', '/api/v1/finance/purchases', $token, $purchase)->assertUnprocessable();
        $this->requestWithToken('GET', '/api/v1/finance/reports', $token)->assertOk()->assertJsonPath('data.totals.revenues', '100.30')->assertJsonPath('data.totals.net_flow', '49.95');
        $pdf = $this->requestWithToken('GET', '/api/v1/finance/reports/export/pdf', $token)->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $xlsx = $this->requestWithToken('GET', '/api/v1/finance/reports/export/xlsx', $token)->assertOk()->getContent();
        $this->assertStringStartsWith('PK', $xlsx);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->requestWithToken('POST', '/api/v1/finance/logout', $token)->assertOk();
        $this->requestWithToken('GET', '/api/v1/finance/me', $token)->assertUnauthorized();
    }

    public static function roles(): array
    {
        return ['entry' => ['finance_entry', 200, 403], 'accountant' => ['finance_accountant', 200, 200], 'manager' => ['finance_manager', 200, 200], 'customer' => ['customer', 403, 403]];
    }

    #[DataProvider('roles')]
    public function test_real_login_permissions_and_restaurant_isolation(string $role, int $financeStatus, int $reportStatus): void
    {
        $branch = $this->prepare();
        $token = $this->login($role, $branch);
        $this->requestWithToken('GET', '/api/v1/finance/me', $token)->assertStatus($financeStatus);
        $this->requestWithToken('GET', '/api/v1/finance/reports/export/xlsx', $token)->assertStatus($reportStatus);
        $this->requestWithToken('GET', '/api/v1/admin/me', $token)->assertForbidden();
        $this->requestWithToken('GET', '/api/v1/admin/orders', $token)->assertForbidden();
        $this->requestWithToken('GET', '/api/v1/admin/products', $token)->assertForbidden();
    }

    public function test_customer_origin_without_financial_credentials_does_not_receive_financial_data(): void
    {
        $branch = $this->prepare();
        $headers = ['Host' => 'shishlifresh.com', 'Origin' => 'https://shishlifresh.com'];
        $this->getJson('https://shishlifresh.com/api/v1/finance/revenues', $headers)->assertNotFound();
        $this->requestWithToken('GET', '/api/v1/finance/revenues', 'invalid-review-token', [], $headers)->assertNotFound();
        $token = $this->login('customer', $branch);
        $this->requestWithToken('GET', '/api/v1/finance/revenues', $token, [], $headers)->assertNotFound();
    }

    public function test_finance_api_is_rejected_on_customer_host_even_with_a_financial_token(): void
    {
        $branch = $this->prepare();
        $token = $this->login('finance_manager', $branch);
        $this->requestWithToken('GET', '/api/v1/finance/me', $token, [], ['Host' => 'shishlifresh.com'])->assertNotFound();
    }

    public function test_financial_responses_and_errors_are_not_stored_in_shared_caches(): void
    {
        $branch = $this->prepare();
        $token = $this->login('finance_entry', $branch);
        foreach ([['/api/v1/finance/me', 200], ['/api/v1/finance/reports', 403]] as [$path, $status]) {
            $response = $this->requestWithToken('GET', $path, $token)->assertStatus($status);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/v1/finance/me')->assertUnauthorized();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''), '401 financial responses need the same no-store policy as successful financial responses.');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_cors_preflight_allows_only_the_configured_finance_origin(): void
    {
        $this->prepare();
        config(['cors.allowed_origins' => ['https://finance.shishlifresh.com']]);
        $headers = ['Origin' => 'https://finance.shishlifresh.com', 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'Authorization'];
        $this->options('/api/v1/finance/me', [], $headers)->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://finance.shishlifresh.com')->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->app->forgetInstance(CorsService::class);
        $response = $this->options('/api/v1/finance/me', [], [...$headers, 'Origin' => 'https://untrusted.example']);
        $this->assertNotSame('https://untrusted.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_finance_seeder_can_be_repeated_on_a_fresh_test_database(): void
    {
        $this->prepare();
        $sources = RevenueSource::pluck('uuid')->all();
        $permissions = Permission::count();
        $roles = Role::count();
        RevenueSource::firstOrFail()->update(['is_active' => false]);
        $this->seed(FinanceSeeder::class);
        $this->seed(FinanceSeeder::class);
        $this->assertSame($sources, RevenueSource::pluck('uuid')->all());
        $this->assertDatabaseCount('finance_revenue_sources', 6);
        $this->assertDatabaseCount('finance_expense_categories', 5);
        $this->assertSame($permissions, Permission::count());
        $this->assertSame($roles, Role::count());
        $this->assertFalse(RevenueSource::firstOrFail()->is_active);
    }

    public static function editableDefaults(): array
    {
        return ['revenue source' => [RevenueSource::class, 6], 'expense category' => [ExpenseCategory::class, 5]];
    }

    #[DataProvider('editableDefaults')]
    public function test_reseeding_does_not_recreate_a_default_item_after_its_name_was_changed(string $model, int $expectedCount): void
    {
        $this->prepare();
        $model::firstOrFail()->update(['name' => 'اسم معدل في المراجعة']);
        $this->seed(FinanceSeeder::class);
        $this->assertSame($expectedCount, $model::count());
    }

    public function test_login_issues_a_time_bounded_financial_token(): void
    {
        $branch = $this->prepare();
        $token = $this->login('finance_manager', $branch);
        $record = PersonalAccessToken::findToken($token);
        $this->assertTrue($record->expires_at !== null || config('sanctum.expiration') !== null, 'Financial access tokens currently have neither individual nor global expiration.');
    }
}
