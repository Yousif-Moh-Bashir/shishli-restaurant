<?php

namespace Tests\Feature;

use App\Actions\Customers\ClaimGuestOrdersAction;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\RestaurantSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderTrackingSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function guest(): Order
    {
        return Order::factory()->guest()->create(['access_token' => str_repeat('a', 64)]);
    }

    private function track(Order $order, string $token = ''): TestResponse
    {
        return $this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'token' => $token === '' ? str_repeat('a', 64) : $token]);
    }

    public function test_guest_token_hash_is_stored_and_valid_tracking_contains_no_credential(): void
    {
        $order = $this->guest();
        $this->assertSame(hash('sha256', str_repeat('a', 64)), $order->fresh()->guest_access_token_hash);
        $this->assertNull($order->fresh()->access_token);
        $this->assertFalse(Schema::hasColumn('orders', 'access_token'));
        $response = $this->track($order)->assertOk()->assertJsonPath('data.order.number', $order->order_number)
            ->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.order.guest_access_token_hash');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(str_repeat('a', 64), $response->getContent());
        $this->assertArrayNotHasKey('guest_access_token_hash', $order->toArray());
    }

    public function test_wrong_token_and_unknown_number_have_identical_generic_404(): void
    {
        $order = $this->guest();
        $wrong = $this->track($order, str_repeat('b', 64))->assertNotFound();
        $unknown = $this->postJson('/api/v1/orders/track', ['order_number' => 'SH-NOT-EXISTING', 'token' => str_repeat('a', 64)])->assertNotFound();
        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertSame('تعذر العثور على الطلب أو بيانات التتبع غير صحيحة.', $wrong->json('message'));
    }

    #[DataProvider('invalidCredentials')]
    public function test_tracking_requires_body_credentials_and_validates_shapes(array $payload, array $fields): void
    {
        $this->postJson('/api/v1/orders/track', $payload)->assertUnprocessable()->assertJsonValidationErrors($fields);
    }

    public static function invalidCredentials(): array
    {
        return [[[], ['order_number', 'token']], [['order_number' => 'SH-123'], ['token']],
            [['order_number' => [], 'token' => str_repeat('a', 64)], ['order_number']],
            [['order_number' => str_repeat('x', 81), 'token' => str_repeat('a', 64)], ['order_number']],
            [['order_number' => 'SH-123', 'token' => []], ['token']],
            [['order_number' => 'SH-123', 'token' => '123456'], ['token']],
            [['order_number' => 'SH-123', 'token' => str_repeat('?', 64)], ['token']]];
    }

    public function test_query_credentials_and_get_requests_are_not_a_tracking_access_path(): void
    {
        $order = $this->guest();
        $query = '?'.http_build_query(['order_number' => $order->order_number, 'token' => str_repeat('a', 64)]);
        $this->postJson('/api/v1/orders/track'.$query, [])->assertUnprocessable();
        $this->getJson('/api/v1/orders/track'.$query)->assertStatus(405);
        $this->track($order)->assertOk();
    }

    public function test_customer_ownership_required_and_admin_cannot_bypass_it(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = User::factory()->create()->assignRole('customer');
        $order = Order::factory()->for($owner)->create();
        $path = '/api/v1/orders/'.$order->uuid.'/tracking';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($owner, 'sanctum')->getJson($path)->assertOk();
        foreach (['customer', 'manager', 'super_admin'] as $role) {
            $other = User::factory()->create()->assignRole($role);
            $this->actingAs($other, 'sanctum')->getJson($path)->assertNotFound();
        }
    }

    public function test_registered_order_cannot_be_accessed_with_guest_token(): void
    {
        $order = Order::factory()->for(User::factory())->create(['access_token' => str_repeat('a', 64)]);
        $this->track($order)->assertNotFound();
    }

    public function test_claim_revokes_hash_and_expiry_and_owner_can_track(): void
    {
        $order = Order::factory()->create(['access_token' => str_repeat('a', 64), 'customer_phone' => '+249912345678']);
        $owner = User::factory()->create(['phone' => '+249912345678', 'phone_verified_at' => now()]);
        $this->track($order)->assertOk();
        $this->assertSame(1, app(ClaimGuestOrdersAction::class)->handle($owner));
        $this->assertNull($order->fresh()->guest_access_token_hash);
        $this->assertNull($order->fresh()->guest_access_token_expires_at);
        $this->track($order)->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/orders/'.$order->uuid.'/tracking')->assertOk();
    }

    public function test_expiry_boundary_denies_guest_but_never_denies_authenticated_owner(): void
    {
        $this->freezeTime();
        $order = $this->guest();
        $order->forceFill(['guest_access_token_expires_at' => now()->addSecond()])->save();
        $this->track($order)->assertOk();
        $this->travel(1)->seconds();
        $this->track($order)->assertNotFound();
        $owner = User::factory()->create();
        $order->update(['user_id' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/orders/'.$order->uuid.'/tracking')->assertOk();
    }

    public function test_token_default_lifetime_is_thirty_days_and_setting_controls_new_issuance(): void
    {
        $this->travelTo(now()->startOfSecond());
        $first = $this->guest();
        $this->assertTrue($first->guest_access_token_expires_at->equalTo(now()->addDays(30)));
        DB::table('restaurant_settings')->insert(['key' => 'orders.guest_tracking_token_days', 'value' => '7', 'type' => 'integer', 'group' => 'orders', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()]);
        app(RestaurantSettingsService::class)->forget();
        $next = Order::factory()->create(['access_token' => str_repeat('b', 64)]);
        $this->assertTrue($next->guest_access_token_expires_at->equalTo(now()->addDays(7)));
        $this->assertTrue($first->fresh()->guest_access_token_expires_at->equalTo(now()->addDays(30)));
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonMissingPath('data.orders.guest_tracking_token_days');
    }

    public function test_checkout_issues_raw_credential_once_and_retry_does_not_reissue_it(): void
    {
        Log::spy();
        $item = CartItem::factory()->create();
        $cart = $item->cart;
        $token = str_repeat('c', 64);
        $cart->token = hash('sha256', $token);
        $cart->save();
        $this->withHeader('X-Cart-Token', $token)->withHeader('X-Cart-UUID', $cart->uuid);
        $payload = ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'عميل', 'phone' => '0501234567']];
        $first = $this->postJson('/api/v1/checkout', $payload)->assertCreated();
        $raw = $first->json('data.tracking.token');
        $this->assertSame(64, strlen($raw));
        $this->assertSame($raw, $first->json('data.access_token'));
        $order = Order::firstOrFail();
        $this->assertSame(hash('sha256', $raw), $order->guest_access_token_hash);
        $this->postJson('/api/v1/checkout', $payload)->assertOk()->assertJsonMissingPath('data.tracking')->assertJsonMissingPath('data.access_token');
        $this->track($order, $raw)->assertOk();
        $this->assertStringNotContainsString($raw, json_encode(DB::table('orders')->first(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($raw, json_encode(DB::table('outbound_notifications')->get(), JSON_THROW_ON_ERROR));
        foreach (['error', 'warning', 'info', 'debug', 'notice', 'critical'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$arguments): bool => str_contains(json_encode($arguments), $raw));
        }
    }

    public function test_tracking_privacy_excludes_notes_actor_identifiers_full_contact_and_payment_metadata(): void
    {
        $order = Order::factory()->delivery()->create(['access_token' => str_repeat('a', 64), 'customer_phone' => '0501234567', 'customer_email' => 'private@example.com',
            'customer_notes' => 'private customer note', 'cancellation_reason' => 'secret admin reason']);
        $actor = User::factory()->create();
        OrderStatusHistory::factory()->for($order)->create(['note' => 'secret admin note', 'changed_by' => $actor->id, 'metadata' => ['secret' => 'internal']]);
        $order->payments()->create(['method' => 'cash', 'status' => 'pending', 'amount' => '10.00', 'currency' => 'SAR', 'idempotency_key' => 'private-payment', 'provider_payment_id' => 'provider-secret']);
        $response = $this->track($order)->assertOk()->assertJsonPath('data.customer.phone_masked', '050****567');
        foreach (['private@example.com', '0501234567', 'secret admin', 'private customer note', 'provider-secret', 'changed_by', 'metadata', 'user_id', 'branch_id', 'building_number', 'recipient_name'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame(['district', 'street'], array_keys($response->json('data.destination')));
    }

    public function test_no_raw_token_in_application_log_calls_or_notification_snapshots(): void
    {
        Log::spy();
        $order = $this->guest();
        $this->track($order)->assertOk();
        $this->track($order, str_repeat('b', 64))->assertNotFound();
        foreach (['error', 'warning', 'info', 'debug', 'notice', 'critical'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$arguments): bool => str_contains(json_encode($arguments), str_repeat('a', 64)));
        }
        $this->assertStringNotContainsString(str_repeat('a', 64), json_encode(DB::table('outbound_notifications')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_legacy_token_migration_preserves_access_without_retaining_raw_credentials(): void
    {
        $order = Order::factory()->create();
        $migration = require database_path('migrations/2026_10_03_021236_add_secure_guest_tracking_and_estimates_to_orders.php');
        $migration->down();
        try {
            DB::table('orders')->where('id', $order->id)->update(['access_token' => str_repeat('a', 64)]);
            $migration->up();
            $this->assertFalse(Schema::hasColumn('orders', 'access_token'));
            $this->assertSame(hash('sha256', str_repeat('a', 64)), $order->fresh()->guest_access_token_hash);
            $this->track($order)->assertOk();
        } finally {
            if (Schema::hasColumn('orders', 'access_token')) {
                $migration->up();
            }
        }
    }
}
