<?php

namespace Tests\Feature;

use App\Actions\Customers\ClaimGuestOrdersAction;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GuestCustomersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    public function test_guest_orders_aggregate_by_normalized_phone_and_exclude_registered_orders(): void
    {
        config(['outbound_notifications.phone_country' => 'SA']);
        $admin = $this->admin();
        Order::factory()->guest()->completed()->create(['customer_phone' => '0501234567', 'customer_name' => 'old', 'total' => '10.10', 'placed_at' => '2026-01-01']);
        Order::factory()->guest()->completed()->create(['customer_phone' => '+966501234567', 'customer_name' => 'latest', 'total' => '0.20', 'placed_at' => '2026-01-02']);
        Order::factory()->guest()->create(['customer_phone' => '966501234567', 'customer_name' => 'latest tie', 'total' => '500.00', 'placed_at' => '2026-01-02']);
        Order::factory()->guest()->cancelled()->create(['customer_phone' => '+966501234567', 'total' => '500.00', 'placed_at' => '2026-01-01']);
        Order::factory()->for(User::factory())->completed()->create(['customer_phone' => '+966501234567', 'total' => '900.00']);
        Order::factory()->guest()->create(['customer_phone' => '+249912345678']);
        $this->actingAs($admin)->getJson('/api/v1/admin/guest-customers?'.http_build_query(['phone' => '0501234567']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.phone', '+966501234567')
            ->assertJsonPath('data.0.latest_name', 'latest tie')->assertJsonPath('data.0.orders_count', 4)
            ->assertJsonPath('data.0.completed_orders_count', 2)->assertJsonPath('data.0.cancelled_orders_count', 1)
            ->assertJsonPath('data.0.total_spent', '10.30')->assertJsonPath('data.0.last_order_at', '2026-01-02T00:00:00.000000Z');
        $this->getJson('/api/v1/admin/guest-customers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_orders_endpoint_requires_phone_and_only_returns_matching_unowned_orders(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->guest()->create(['customer_phone' => '+249912345678']);
        Order::factory()->for(User::factory())->create(['customer_phone' => '+249912345678']);
        Order::factory()->guest()->create(['customer_phone' => '+249911111111']);
        $this->actingAs($admin)->getJson('/api/v1/admin/guest-customers/orders?'.http_build_query(['phone' => '+249912345678']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->uuid);
        $this->getJson('/api/v1/admin/guest-customers/orders')->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->getJson('/api/v1/admin/guest-customers?phone=invalid')->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_unknown_local_phone_is_not_assumed_or_grouped(): void
    {
        config(['outbound_notifications.phone_country' => null]);
        $admin = $this->admin();
        $order = Order::factory()->guest()->create(['customer_phone' => '0501234567']);
        $this->assertNull($order->customer_phone_normalized);
        $this->actingAs($admin)->getJson('/api/v1/admin/guest-customers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_verified_customer_claims_matching_guests_once_without_changing_snapshots(): void
    {
        $user = User::factory()->verifiedPhone()->create();
        $guest = Order::factory()->guest()->delivery()->create(['customer_phone' => $user->phone, 'customer_name' => 'original', 'customer_email' => 'original@example.com']);
        $before = $guest->only(['customer_name', 'customer_phone', 'customer_email', 'total', 'status']);
        $address = $guest->address->getAttributes();
        $otherPhone = Order::factory()->guest()->create(['customer_phone' => '+249911111111']);
        $registered = Order::factory()->for(User::factory())->create(['customer_phone' => $user->phone]);
        $this->actingAs($user)->postJson('/api/v1/profile/claim-orders')->assertOk()->assertJsonPath('data.claimed_orders', 1);
        $this->assertSame($user->id, $guest->fresh()->user_id);
        $this->assertSame($before, $guest->fresh()->only(array_keys($before)));
        $this->assertSame($address, $guest->fresh()->address->getAttributes());
        $this->assertNull($otherPhone->fresh()->user_id);
        $this->assertSame($registered->user_id, $registered->fresh()->user_id);
        $this->postJson('/api/v1/profile/claim-orders')->assertOk()->assertJsonPath('data.claimed_orders', 0);
        $this->getJson('/api/v1/orders/'.$guest->uuid)->assertOk();
    }

    public function test_unverified_phone_cannot_claim_orders(): void
    {
        $user = User::factory()->unverifiedPhone()->create(['phone' => '+249912345678']);
        $order = Order::factory()->guest()->create(['customer_phone' => $user->phone]);
        $this->actingAs($user)->postJson('/api/v1/profile/claim-orders')->assertForbidden();
        $this->assertNull($order->fresh()->user_id);
    }

    #[DataProvider('forgedClaimFields')]
    public function test_customer_cannot_submit_another_identity_to_claim(array $payload, string $field): void
    {
        $user = User::factory()->verifiedPhone()->create();
        $order = Order::factory()->guest()->create(['customer_phone' => '+249911111111']);
        $this->actingAs($user)->postJson('/api/v1/profile/claim-orders', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNull($order->fresh()->user_id);
    }

    public static function forgedClaimFields(): array
    {
        return [[['phone' => '+249911111111'], 'phone'], [['phone_normalized' => '+249911111111'], 'phone_normalized'],
            [['customer_phone_normalized' => '+249911111111'], 'customer_phone_normalized'], [['user_id' => 1], 'user_id']];
    }

    public function test_direct_action_reads_fresh_verification_and_activity_state(): void
    {
        $user = User::factory()->verifiedPhone()->create();
        DB::table('users')->where('id', $user->id)->update(['phone_verified_at' => null]);
        $this->expectException(HttpException::class);
        app(ClaimGuestOrdersAction::class)->handle($user);
    }

    public function test_normalized_user_phone_uniqueness_prevents_duplicate_identity_formats(): void
    {
        config(['outbound_notifications.phone_country' => 'SA']);
        User::factory()->create(['phone' => '0501234567']);
        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['phone' => '+966501234567']);
    }

    public function test_backend_ignores_forged_order_normalized_phone(): void
    {
        $order = Order::factory()->create(['customer_phone' => '+249912345678', 'customer_phone_normalized' => '+249911111111']);
        $this->assertSame('+249912345678', $order->customer_phone_normalized);
    }

    public function test_normalization_command_is_chunked_restartable_and_preserves_snapshots(): void
    {
        $first = Order::factory()->create(['customer_phone' => '+249912345678']);
        $invalid = Order::factory()->create(['customer_phone' => 'invalid']);
        DB::table('orders')->where('id', $first->id)->update(['customer_phone_normalized' => null]);
        $original = $first->fresh()->getAttributes();
        $original['customer_phone_normalized'] = '+249912345678';
        $this->artisan('customers:normalize-order-phones', ['--chunk' => 1])->expectsOutput('Normalized: 1; skipped: 1.')->assertExitCode(0);
        $this->assertSame($original, $first->fresh()->getAttributes());
        $this->assertNull($invalid->fresh()->customer_phone_normalized);
        $this->artisan('customers:normalize-order-phones')->expectsOutput('Normalized: 0; skipped: 1.')->assertExitCode(0);
    }

    public function test_claim_requires_phone_normalization_to_match_server_identity(): void
    {
        $user = User::factory()->verifiedPhone()->create();
        DB::table('users')->where('id', $user->id)->update(['phone_normalized' => null]);
        $this->actingAs($user)->postJson('/api/v1/profile/claim-orders')->assertForbidden();
    }

    #[DataProvider('checkoutIdentities')]
    public function test_checkout_stores_server_normalized_phone_for_guest_and_registered_customer(bool $authenticated): void
    {
        config(['outbound_notifications.phone_country' => 'SA']);
        if ($authenticated) {
            $this->actingAs(User::factory()->create(), 'sanctum');
        }
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => '20.00']);
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        if (! $authenticated) {
            $this->withHeader('X-Cart-Token', $cart->json('data.token'));
        }
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/v1/checkout', ['type' => 'pickup', 'payment_method' => 'cash',
            'customer' => ['name' => 'Original', 'phone' => '0501234567'], 'customer_phone_normalized' => '+249911111111'])
            ->assertCreated()->assertJsonPath('data.customer.phone', '0501234567');
        $order = Order::firstOrFail();
        $this->assertSame('+966501234567', $order->customer_phone_normalized);
        $this->assertSame($authenticated, $order->user_id !== null);
    }

    public static function checkoutIdentities(): array
    {
        return [[false], [true]];
    }

    public function test_registration_never_verifies_phone_or_automatically_claims_guest_orders(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $guest = Order::factory()->guest()->create(['customer_phone' => '+249912345678']);
        $this->postJson('/api/v1/register', ['name' => 'Customer', 'email' => 'new@example.com', 'phone' => '+249912345678',
            'password' => 'password123', 'password_confirmation' => 'password123', 'phone_verified_at' => now()->toISOString()])
            ->assertCreated();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNull($user->phone_verified_at);
        $this->assertNull($guest->fresh()->user_id);
    }

    public function test_overlapping_claim_invocation_cannot_reassign_an_already_claimed_order(): void
    {
        $user = User::factory()->verifiedPhone()->create();
        $guest = Order::factory()->guest()->create(['customer_phone' => $user->phone]);
        $entered = false;
        $secondResult = null;
        DB::listen(function ($query) use ($user, &$entered, &$secondResult): void {
            if (! $entered && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'orders')) {
                $entered = true;
                $secondResult = app(ClaimGuestOrdersAction::class)->handle($user);
            }
        });
        $this->assertSame(1, app(ClaimGuestOrdersAction::class)->handle($user));
        $this->assertSame(0, $secondResult);
        $this->assertSame($user->id, $guest->fresh()->user_id);
    }

    public function test_claim_changes_are_rolled_back_with_the_transaction(): void
    {
        $user = User::factory()->verifiedPhone()->create();
        $guest = Order::factory()->guest()->create(['customer_phone' => $user->phone]);
        DB::beginTransaction();
        $this->assertSame(1, app(ClaimGuestOrdersAction::class)->handle($user));
        DB::rollBack();
        $this->assertNull($guest->fresh()->user_id);
    }

    public function test_invalid_chunk_size_does_not_modify_order_phones(): void
    {
        $order = Order::factory()->create(['customer_phone' => '+249912345678']);
        DB::table('orders')->where('id', $order->id)->update(['customer_phone_normalized' => null]);
        $this->artisan('customers:normalize-order-phones', ['--chunk' => 0])->assertExitCode(1);
        $this->assertNull($order->fresh()->customer_phone_normalized);
    }
}
