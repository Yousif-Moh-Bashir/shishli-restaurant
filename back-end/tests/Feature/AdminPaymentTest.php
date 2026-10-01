<?php

namespace Tests\Feature;

use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPaymentTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminEndpoints')]
    public function test_admin_payment_reads_require_authentication_and_view_permission(string $endpoint): void
    {
        $this->seed(RolePermissionSeeder::class);
        $payment = Payment::factory()->create();
        $path = str_replace('{payment}', $payment->uuid, $endpoint);
        $this->getJson($path)->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson($path)->assertForbidden();
        $user->givePermissionTo('payments.view');
        $this->getJson($path)->assertOk();
    }

    public static function adminEndpoints(): array
    {
        return [['/api/v1/admin/payments'], ['/api/v1/admin/payments/{payment}'], ['/api/v1/admin/payments/{payment}/transactions']];
    }

    public function test_admin_filters_and_pagination_find_only_matching_payment(): void
    {
        $this->freezeTime();
        $manager = $this->manager();
        $match = Payment::factory()->online()->paid()->create(['created_at' => '2026-09-15 12:00:00']);
        Payment::factory()->online()->failed()->create(['created_at' => '2026-09-15 12:00:00']);
        Payment::factory()->cash()->paid()->create(['created_at' => '2026-09-15 12:00:00']);
        Payment::factory()->online()->paid()->create(['created_at' => '2026-09-14 12:00:00']);
        $filters = ['status' => 'paid', 'method' => 'card', 'provider' => 'test',
            'order_number' => $match->order->order_number, 'date_from' => '2026-09-15', 'date_to' => '2026-09-15', 'per_page' => 1];
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/payments?'.http_build_query($filters))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->uuid)->assertJsonPath('meta.total', 1);
        unset($filters['order_number']);
        $this->getJson('/api/v1/admin/payments?'.http_build_query($filters))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/payments?status=bad&method=bad&per_page=101&date_from=2026-09-15&date_to=2026-09-14')
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'method', 'per_page', 'date_to']);
    }

    public function test_show_and_transactions_resources_exclude_secrets_and_internal_identifiers(): void
    {
        $manager = $this->manager();
        $payment = Payment::factory()->paid()->create(['idempotency_key' => 'private-key',
            'metadata' => ['secret' => 'private-secret'], 'failure_message' => 'private-error']);
        $transaction = PaymentTransaction::factory()->for($payment)->succeeded()->create(['metadata' => ['cvv' => '123']]);
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/payments/'.$payment->uuid)->assertOk()
            ->assertJsonPath('data.id', $payment->uuid)->assertJsonMissingPath('data.order_id')
            ->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.metadata')
            ->assertJsonMissingPath('data.failure_message')->assertJsonMissingPath('data.transactions');
        $this->getJson('/api/v1/admin/payments/'.$payment->uuid.'/transactions?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $transaction->uuid)->assertJsonPath('data.0.amount', '85.00')
            ->assertJsonMissingPath('data.0.payment_id')->assertJsonMissingPath('data.0.metadata');
        $this->patchJson('/api/v1/admin/payments/'.$payment->uuid, ['status' => 'paid'])->assertMethodNotAllowed();
    }

    public function test_owner_can_list_attempts_and_other_users_cannot(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->for($owner)->create(['total' => '85.00']);
        $first = Payment::factory()->for($order)->failed()->create();
        $second = Payment::factory()->for($order)->pending()->create();
        $path = '/api/v1/orders/'.$order->uuid.'/payments';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($path)->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson($path)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->uuid)->assertJsonPath('data.1.id', $first->uuid);
    }

    public function test_order_resource_loads_latest_payment_only_when_requested(): void
    {
        $order = Order::factory()->create(['total' => '85.00']);
        $payment = Payment::factory()->for($order)->create();
        $request = request();
        $plain = (new OrderResource($order))->resolve($request);
        $this->assertArrayNotHasKey('latest_payment', $plain);
        $loaded = (new OrderResource($order->load('latestPayment')))->resolve($request);
        $this->assertSame($payment->uuid, $loaded['latest_payment']->resolve($request)['id']);
    }

    private function manager(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole('manager');
    }
}
