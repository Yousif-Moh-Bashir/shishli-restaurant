<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Events\OrderPlaced;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_pickup_creates_snapshots_and_repeated_checkout_returns_same_order(): void
    {
        [$cart, $assignment, $group, $value] = $this->cart();
        Event::fake([OrderPlaced::class]);
        $response = $this->postJson('/api/v1/checkout', $this->payload())->assertCreated()
            ->assertJsonPath('data.type', 'pickup')->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.pricing.subtotal', '67.47')
            ->assertJsonPath('data.pricing.total', '67.47')->assertJsonPath('data.pricing.delivery_fee', '0.00')
            ->assertJsonPath('data.pricing.discount_total', '0.00')->assertJsonPath('data.pricing.tax_total', '0.00')
            ->assertJsonPath('data.pricing.currency', 'SAR')->assertJsonPath('data.address', null)
            ->assertJsonPath('data.items.0.product.id', $assignment->product->uuid)
            ->assertJsonPath('data.items.0.product.sku', $assignment->product->sku)
            ->assertJsonPath('data.items.0.options.0.group.id', $group->uuid)
            ->assertJsonPath('data.items.0.options.0.value.id', $value->uuid);
        $this->assertSame(CartStatus::Converted, $cart->fresh()->status);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_addresses', 0);
        $this->assertSame(64, strlen($response->json('data.access_token')));
        $this->postJson('/api/v1/checkout', $this->payload())->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'))
            ->assertJsonPath('data.access_token', $response->json('data.access_token'));
        $this->assertDatabaseCount('orders', 1);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        $this->getJson('/api/v1/cart')->assertNotFound();
    }

    public function test_authenticated_checkout_and_explicit_cart_retry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        [$cart] = $this->cart();
        $this->withHeader('X-Cart-UUID', $cart->uuid);
        $response = $this->postJson('/api/v1/checkout', $this->payload())->assertCreated()->assertJsonMissingPath('data.access_token');
        $this->assertSame($user->id, Order::firstOrFail()->user_id);
        $this->postJson('/api/v1/checkout', $this->payload())->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
    }

    public function test_frontend_prices_and_statuses_cannot_override_server_values(): void
    {
        $this->cart();
        $payload = $this->payload();
        $payload['payment_method'] = 'card';
        $payload += ['subtotal' => '0.01', 'total' => '0.01', 'delivery_fee' => '99.99', 'unit_price' => '0.01',
            'line_total' => '0.01', 'option_price' => '0.01', 'discount' => '99.00', 'tax' => '99.00',
            'payment_status' => 'paid', 'order_status' => 'completed', 'status' => 'completed', 'zone_uuid' => fake()->uuid()];
        $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.pricing.total', '67.47')
            ->assertJsonPath('data.items.0.pricing.unit_price', '22.49')->assertJsonPath('data.payment.method', 'card')
            ->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('orders', ['total' => '67.47', 'payment_status' => 'pending', 'status' => 'pending']);
    }

    public function test_authenticated_retry_without_cart_header_returns_last_converted_cart(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $this->cart();
        $this->withoutHeader('X-Cart-UUID')->withoutHeader('X-Cart-Token');
        $first = $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        $this->postJson('/api/v1/checkout', $this->payload())->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_other_user_cannot_checkout_or_replay_a_cart(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        [$cart] = $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        $this->actingAs(User::factory()->create(), 'sanctum')->withHeader('X-Cart-UUID', $cart->uuid)
            ->postJson('/api/v1/checkout', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_multiple_items_total_is_sum_of_current_line_totals(): void
    {
        [$cart, $assignment] = $this->cart();
        $product = Product::factory()->create(['base_price' => '10.01']);
        $assignment->branch->products()->attach($product);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 2])->assertCreated();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated()
            ->assertJsonCount(2, 'data.items')->assertJsonPath('data.pricing.subtotal', '87.49')
            ->assertJsonPath('data.pricing.total', '87.49');
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_new_required_option_group_prevents_checkout_with_structured_issues(): void
    {
        [$cart, $assignment] = $this->cart();
        $group = OptionGroup::factory()->create(['is_required' => true, 'min_select' => 1]);
        $assignment->product->optionGroups()->attach($group);
        $this->postJson('/api/v1/checkout', $this->payload())->assertUnprocessable()
            ->assertJsonPath('errors.cart.0.code', 'OPTIONS_CONFIGURATION_CHANGED')
            ->assertJsonPath('errors.cart.0.item_uuid', $cart->items()->first()->uuid);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_authenticated_one_time_delivery_address_is_not_saved_to_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        [$cart, $assignment] = $this->cart();
        DeliveryZone::factory()->for($assignment->branch)->create();
        $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address' => $this->address()])
            ->assertCreated()->assertJsonPath('data.address.recipient_name', 'Recipient');
        $this->assertDatabaseCount('customer_addresses', 0);
        $this->assertDatabaseCount('order_addresses', 1);
    }

    #[DataProvider('prices')]
    public function test_checkout_uses_current_prices(string $change, string $expected): void
    {
        [$cart, $assignment, $group, $value] = $this->cart();
        match ($change) {
            'product' => $assignment->product->update(['base_price' => '21.01']),
            'branch' => $assignment->update(['price_override' => '25.00']),
            'option' => $value->update(['price_modifier' => '3.51']),
        };
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated()->assertJsonPath('data.pricing.total', $expected);
        $this->assertSame($expected, $cart->fresh()->total);
    }

    public static function prices(): array
    {
        return ['product' => ['product', '70.53'], 'branch' => ['branch', '82.50'], 'option' => ['option', '70.50']];
    }

    #[DataProvider('invalidCarts')]
    public function test_invalid_cart_cannot_checkout(string $change, int $status): void
    {
        [$cart, $assignment, $group, $value] = $this->cart();
        match ($change) {
            'empty' => $cart->items()->delete(),
            'expired' => $cart->update(['expires_at' => now()->subMinute()]),
            'abandoned' => $cart->update(['status' => CartStatus::Abandoned]),
            'product unavailable' => $assignment->product->update(['is_available' => false]),
            'product inactive' => $assignment->product->update(['is_active' => false]),
            'product deleted' => $assignment->product->delete(),
            'category inactive' => $assignment->product->category->update(['is_active' => false]),
            'option inactive' => $value->update(['is_active' => false]),
            'option deleted' => $value->delete(),
            'group inactive' => $group->update(['is_active' => false]),
            'group detached' => $assignment->product->optionGroups()->detach($group),
            'product detached' => $assignment->delete(),
            'assignment unavailable' => $assignment->update(['is_available' => false]),
            'required changed' => $assignment->product->optionGroups()->updateExistingPivot($group->id, ['min_select_override' => 2]),
            'pickup disabled' => $assignment->branch->update(['supports_pickup' => false]),
            'branch closed' => $assignment->branch->update(['accepts_orders' => false]),
            'branch inactive' => $assignment->branch->update(['is_active' => false]),
            'branch deleted' => $assignment->branch->delete(),
        };
        Event::fake([OrderPlaced::class]);
        $this->postJson('/api/v1/checkout', $this->payload())->assertStatus($status);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertNotSame(CartStatus::Converted, $cart->fresh()->status);
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public static function invalidCarts(): array
    {
        $cases = [];
        foreach (['empty', 'expired', 'abandoned', 'product unavailable', 'product inactive', 'product deleted', 'category inactive',
            'option inactive', 'option deleted', 'group inactive', 'group detached', 'product detached', 'assignment unavailable',
            'required changed', 'pickup disabled', 'branch closed', 'branch inactive', 'branch deleted'] as $case) {
            $cases[$case] = [$case, in_array($case, ['expired', 'abandoned'], true) ? 404 : 422];
        }

        return $cases;
    }

    public function test_guest_delivery_recalculates_fee_and_preserves_full_history(): void
    {
        [$cart, $assignment, $group, $value] = $this->cart();
        $zone = DeliveryZone::factory()->for($assignment->branch)->create(['delivery_fee' => '7.25']);
        $payload = $this->payload('delivery') + ['address' => $this->address(), 'delivery_fee' => '0.00'];
        $group->update(['name' => 'Current size']);
        $value->update(['name' => 'Current large']);
        $response = $this->postJson('/api/v1/checkout', $payload)->assertCreated()
            ->assertJsonPath('data.pricing.total', '74.72')->assertJsonPath('data.pricing.delivery_fee', '7.25')
            ->assertJsonPath('data.address.delivery_zone_name', $zone->name)
            ->assertJsonPath('data.address.street', 'Street 1')
            ->assertJsonPath('data.items.0.options.0.group.name', 'Current size')
            ->assertJsonPath('data.items.0.options.0.value.name', 'Current large');
        $original = $response->json('data');
        unset($original['access_token']);
        $assignment->product->update(['name' => 'Changed', 'base_price' => '100.00']);
        $group->update(['name' => 'Changed']);
        $value->update(['name' => 'Changed', 'price_modifier' => '100.00']);
        $zone->update(['name' => 'Changed', 'delivery_fee' => '100.00']);
        $assignment->product->delete();
        $group->delete();
        $value->delete();
        $zone->delete();
        $this->withHeader('X-Order-Token', $response->json('data.access_token'))
            ->getJson('/api/v1/orders/'.$response->json('data.id').'/guest')->assertOk()->assertExactJson([
                'success' => true, 'message' => 'Success', 'data' => $original, 'errors' => null,
            ]);
    }

    public function test_exact_decimal_delivery_total_and_free_threshold(): void
    {
        [$cart, $assignment] = $this->cart();
        $zone = DeliveryZone::factory()->for($assignment->branch)->create();
        $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address' => $this->address()])
            ->assertCreated()->assertJsonPath('data.pricing.total', '72.47');
        [$cart, $assignment] = $this->cart();
        DeliveryZone::factory()->for($assignment->branch)->create(['free_delivery_threshold' => '67.47']);
        $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address' => $this->address()])
            ->assertCreated()->assertJsonPath('data.pricing.total', '67.47')->assertJsonPath('data.pricing.delivery_fee', '0.00');
    }

    #[DataProvider('deliveryFailures')]
    public function test_delivery_failure_rolls_back_repricing(string $change, string $code): void
    {
        [$cart, $assignment] = $this->cart();
        $zone = DeliveryZone::factory()->for($assignment->branch)->create();
        $address = $this->address();
        if ($change === 'outside') {
            $address['district'] = 'Outside';
        }
        if ($change === 'minimum') {
            $zone->update(['minimum_order' => '100.00']);
        }
        if ($change === 'disabled') {
            $assignment->branch->update(['supports_delivery' => false]);
        }
        $assignment->product->update(['base_price' => '21.01']);
        $response = $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address' => $address])->assertUnprocessable();
        if ($code !== '') {
            $response->assertJsonPath('errors.delivery.0.code', $code);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('67.47', $cart->fresh()->total);
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
    }

    public static function deliveryFailures(): array
    {
        return [['outside', 'ADDRESS_OUTSIDE_DELIVERY_AREA'], ['minimum', 'MINIMUM_ORDER_NOT_MET'], ['disabled', '']];
    }

    public function test_saved_address_and_customer_snapshots_survive_edits_and_deletion(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        [$cart, $assignment] = $this->cart();
        DeliveryZone::factory()->for($assignment->branch)->create();
        $address = CustomerAddress::factory()->forUser($user)->create($this->address());
        $response = $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address_uuid' => $address->uuid])->assertCreated();
        $original = $response->json('data');
        $address->update(['street' => 'Changed']);
        $address->delete();
        $user->update(['name' => 'Changed', 'phone' => '000000']);
        $this->getJson('/api/v1/orders/'.$response->json('data.id'))->assertOk()->assertJsonPath('data', $original);
    }

    #[DataProvider('savedAddressAccess')]
    public function test_saved_addresses_require_ownership(bool $authenticated): void
    {
        if ($authenticated) {
            $this->actingAs(User::factory()->create(), 'sanctum');
        }
        $this->cart();
        $address = CustomerAddress::factory()->create();
        $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address_uuid' => $address->uuid])->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public static function savedAddressAccess(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('invalidPayloads')]
    public function test_checkout_payload_validation(array $change, string $error): void
    {
        $this->cart();
        $this->postJson('/api/v1/checkout', array_replace_recursive($this->payload(), $change))
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            [['type' => 'invalid'], 'type'], [['customer' => ['name' => '']], 'customer.name'],
            [['customer' => ['phone' => '']], 'customer.phone'], [['customer' => ['email' => 'invalid']], 'customer.email'],
            [['payment_method' => 'bitcoin'], 'payment_method'], [['notes' => str_repeat('a', 1001)], 'notes'],
            [['type' => 'delivery'], 'address'],
            [['type' => 'delivery', 'address' => ['city' => 'Riyadh']], 'address.recipient_name'],
            [['type' => 'delivery', 'address_uuid' => 'bad'], 'address_uuid'],
        ];
    }

    public function test_delivery_rejects_both_address_sources(): void
    {
        $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address' => $this->address(), 'address_uuid' => fake()->uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['address', 'address_uuid']);
    }

    public function test_missing_or_wrong_cart_credentials_cannot_checkout(): void
    {
        [$cart] = $this->cart();
        $this->withoutHeader('X-Cart-Token')->postJson('/api/v1/checkout', $this->payload())->assertUnauthorized();
        $this->withHeader('X-Cart-Token', str_repeat('x', 64))->postJson('/api/v1/checkout', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_numbers_are_unique_even_with_frozen_time(): void
    {
        $this->freezeTime();
        $generator = app(OrderNumberGenerator::class);
        $numbers = [];
        for ($i = 0; $i < 1000; $i++) {
            $numbers[] = $generator->generate();
        }
        $this->assertCount(1000, array_unique($numbers));
        $this->assertMatchesRegularExpression('/^SH-[0-9]{6}-[0-9A-Z]{26}$/', $numbers[0]);
    }

    private function cart(): array
    {
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => '19.99']);
        $group = OptionGroup::factory()->create(['name' => 'Size']);
        $assignment->product->optionGroups()->attach($group);
        $value = OptionValue::factory()->for($group, 'optionGroup')->create(['name' => 'Large', 'price_modifier' => '2.50']);
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withoutHeader('X-Cart-Token');
        if ($response->json('data.token') !== null) {
            $this->withHeader('X-Cart-Token', $response->json('data.token'));
        }
        $this->withHeader('X-Cart-UUID', $response->json('data.id'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 3,
            'options' => [['option_group_uuid' => $group->uuid, 'option_value_uuids' => [$value->uuid]]]])->assertCreated();

        return [Cart::where('uuid', $response->json('data.id'))->firstOrFail(), $assignment, $group, $value];
    }

    private function payload(string $type = 'pickup'): array
    {
        return ['type' => $type, 'customer' => ['name' => 'Customer', 'phone' => '0501234567', 'email' => 'customer@example.com'],
            'payment_method' => 'cash', 'notes' => 'No onions'];
    }

    private function address(): array
    {
        return ['recipient_name' => 'Recipient', 'phone' => '0507654321', 'city' => 'الرياض', 'district' => 'المصيف',
            'street' => 'Street 1', 'building_number' => '12', 'floor' => '2', 'apartment' => '3',
            'landmark' => 'Shop', 'notes' => 'Call first', 'latitude' => null, 'longitude' => null];
    }
}
