<?php

namespace Tests\Feature;

use App\Actions\Branches\ReplaceBusinessHoursAction;
use App\Actions\Settings\UpdateRestaurantSettingsAction;
use App\Enums\OrderStatus;
use App\Enums\OrderTiming;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\BranchAvailabilityService;
use App\Services\PricingService;
use App\Services\RestaurantSettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalAvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('availabilityCases')]
    public function test_availability_and_checkout_use_deterministic_business_codes(array $settings, array $branchFields, string $type, ?string $code, string $schedule = 'open'): void
    {
        [$cart,$assignment] = $this->cart();
        if ($schedule === 'closed') {
            $assignment->branch->businessHours()->delete();
        }
        if ($schedule === 'closure') {
            BranchClosure::factory()->for($assignment->branch)->active()->create();
        }
        $assignment->branch->update($branchFields);
        if ($settings !== []) {
            $this->settings($settings);
        }
        $branch = $assignment->branch->fresh();
        $result = app(BranchAvailabilityService::class)->evaluate($branch, OrderType::from($type));
        $this->assertSame($code, $result->reasonCode?->value);
        $payload = $this->payload($type);
        if ($type === 'delivery') {
            DeliveryZone::factory()->for($branch)->create(['minimum_order' => '5.00']);
            $payload['address'] = $this->address();
        }
        $response = $this->postJson('/api/v1/checkout', $payload);
        if ($code === null) {
            $response->assertCreated();
        } else {
            $response->assertUnprocessable()->assertJsonPath('errors.availability.0.code', $code);
            $this->assertDatabaseCount('orders', 0);
            $this->assertSame('active', $cart->fresh()->status->value);
        }
    }

    public static function availabilityCases(): array
    {
        return [
            'open pickup' => [[], [], 'pickup', null],
            'open delivery' => [[], [], 'delivery', null],
            'global disabled' => [['orders.enabled' => false], [], 'pickup', 'RESTAURANT_ORDERS_DISABLED'],
            'inactive' => [[], ['is_active' => false], 'pickup', 'BRANCH_INACTIVE'],
            'operational switch' => [[], ['accepts_orders' => false], 'pickup', 'BRANCH_NOT_ACCEPTING_ORDERS'],
            'hours closed' => [[], [], 'pickup', 'BRANCH_CLOSED', 'closed'],
            'closure' => [[], [], 'pickup', 'BRANCH_TEMPORARILY_CLOSED', 'closure'],
            'pickup switch' => [['pickup.enabled' => false], [], 'pickup', 'PICKUP_DISABLED'],
            'delivery switch' => [['delivery.enabled' => false], [], 'delivery', 'DELIVERY_DISABLED'],
            'pickup support' => [[], ['supports_pickup' => false], 'pickup', 'PICKUP_NOT_SUPPORTED'],
            'delivery support' => [[], ['supports_delivery' => false], 'delivery', 'DELIVERY_NOT_SUPPORTED'],
            'maintenance' => [['orders.maintenance_mode' => true], [], 'pickup', 'ORDERING_MAINTENANCE'],
            'precedence global over inactive' => [['orders.enabled' => false], ['is_active' => false], 'pickup', 'RESTAURANT_ORDERS_DISABLED'],
            'precedence maintenance' => [['orders.enabled' => false, 'orders.maintenance_mode' => true], ['is_active' => false], 'pickup', 'ORDERING_MAINTENANCE'],
            'precedence closure over type' => [['pickup.enabled' => false], ['supports_pickup' => false], 'pickup', 'BRANCH_TEMPORARILY_CLOSED', 'closure'],
            'precedence type switch over support' => [['pickup.enabled' => false], ['supports_pickup' => false], 'pickup', 'PICKUP_DISABLED'],
        ];
    }

    public function test_closed_branch_menu_products_and_cart_remain_usable_but_asap_checkout_is_blocked(): void
    {
        [$cart,$assignment] = $this->cart();
        app(ReplaceBusinessHoursAction::class)->handle($assignment->branch, [['day_of_week' => 5, 'opens_at' => '18:00', 'closes_at' => '03:00']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T09:00:00Z'));
        $this->getJson('/api/v1/menu?branch='.$assignment->branch->uuid)->assertOk()
            ->assertJsonCount(1, 'data.categories.0.products')->assertJsonPath('data.availability.is_open', false)
            ->assertJsonPath('data.availability.reason.code', 'BRANCH_CLOSED')->assertJsonPath('data.availability.next_opening_at', '2026-10-02T15:00:00.000000Z');
        $this->getJson('/api/v1/branches/'.$assignment->branch->uuid.'/status')->assertOk()->assertJsonPath('data.is_open', false);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.availability.is_open', false);
        $this->patchJson('/api/v1/cart/items/'.$cart->items()->firstOrFail()->uuid, ['quantity' => 2])->assertOk();
        $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->travelBack();
    }

    public function test_closed_by_schedule_still_enforces_product_availability_and_inactive_branch(): void
    {
        [$cart,$assignment] = $this->cart();
        $assignment->branch->businessHours()->delete();
        $assignment->product->update(['is_available' => false]);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertUnprocessable();
        $assignment->branch->update(['is_active' => false]);
        $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertUnprocessable();
        $this->getJson('/api/v1/branches/'.$assignment->branch->uuid.'/status')->assertNotFound();
        $this->getJson('/api/v1/branches/'.$assignment->branch->uuid.'/business-hours')->assertNotFound();
    }

    public function test_disabled_ordering_blocks_new_carts_but_existing_cart_is_readable(): void
    {
        [$cart,$assignment] = $this->cart();
        $this->settings(['orders.enabled' => false]);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.can_checkout', false);
        $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertUnprocessable();
    }

    public function test_maintenance_keeps_menu_cart_admin_existing_order_and_retry_available(): void
    {
        [$cart,$assignment] = $this->cart();
        $order = $this->postJson('/api/v1/checkout', $this->payload())->assertCreated()->json('data.id');
        $this->settings(['orders.maintenance_mode' => true, 'orders.maintenance_message' => 'صيانة الطلبات']);
        $this->postJson('/api/v1/checkout', $this->payload())->assertOk()->assertJsonPath('data.id', $order);
        $this->getJson('/api/v1/menu?branch='.$assignment->branch->uuid)->assertOk()->assertJsonPath('data.availability.reason.code', 'ORDERING_MAINTENANCE');
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.orders.maintenance_message', 'صيانة الطلبات');
        $this->withoutHeader('X-Cart-UUID')->withoutHeader('X-Cart-Token');
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-UUID', $response->json('data.id'))->withHeader('X-Cart-Token', $response->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertCreated();
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum')->postJson('/api/v1/admin/orders/'.$order.'/confirm')->assertOk();
        $this->assertSame(OrderStatus::Confirmed, Order::where('uuid', $order)->firstOrFail()->status);
    }

    #[DataProvider('scheduledCases')]
    public function test_scheduled_checkout_validates_future_horizon_hours_closures_and_overnight(string $case, ?string $expectedCode, bool $success): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02T07:00:00Z'));
        [$cart,$assignment] = $this->cart();
        app(ReplaceBusinessHoursAction::class)->handle($assignment->branch, [['day_of_week' => 5, 'opens_at' => '18:00', 'closes_at' => '03:00']]);
        $this->settings(['orders.allow_scheduled_orders' => $case !== 'disabled']);
        $payload = $this->payload() + ['order_timing' => 'scheduled', 'scheduled_for' => '2026-10-02T19:00:00+03:00'];
        if ($case === 'missing') {
            unset($payload['scheduled_for']);
        }
        if ($case === 'past') {
            $payload['scheduled_for'] = '2026-10-01T19:00:00+03:00';
        }
        if ($case === 'horizon') {
            $payload['scheduled_for'] = '2026-10-10T19:00:00+03:00';
        }
        if ($case === 'outside') {
            $payload['scheduled_for'] = '2026-10-02T12:00:00+03:00';
        }
        if ($case === 'overnight') {
            $payload['scheduled_for'] = '2026-10-03T01:00:00+03:00';
        }
        if ($case === 'ambiguous') {
            $payload['scheduled_for'] = '2026-10-02 19:00:00';
        }
        if ($case === 'invalid calendar') {
            $payload['scheduled_for'] = '2026-02-30T19:00:00+03:00';
        }
        if ($case === 'closure') {
            BranchClosure::factory()->for($assignment->branch)->create(['starts_at' => '2026-10-02 15:00', 'ends_at' => '2026-10-02 17:00']);
        }
        $response = $this->postJson('/api/v1/checkout', $payload);
        if ($success) {
            $response->assertCreated()->assertJsonPath('data.order_timing', 'scheduled');
            $this->assertSame(OrderTiming::Scheduled, Order::firstOrFail()->order_timing);
            $this->assertSame(CarbonImmutable::parse($payload['scheduled_for'])->utc()->toISOString(), Order::firstOrFail()->scheduled_for->toISOString());
        } else {
            $response->assertUnprocessable();
            if ($expectedCode !== null) {
                $response->assertJsonPath('errors.availability.0.code', $expectedCode);
            }
            $this->assertDatabaseCount('orders', 0);
        }
        $this->travelBack();
    }

    public static function scheduledCases(): array
    {
        return [['disabled', null, false], ['missing', null, false], ['past', null, false], ['horizon', null, false],
            ['inside', null, true], ['outside', 'BRANCH_CLOSED', false], ['closure', 'BRANCH_TEMPORARILY_CLOSED', false],
            ['overnight', null, true], ['ambiguous', null, false], ['invalid calendar', null, false]];
    }

    public function test_asap_rejects_scheduled_timestamp_and_scheduled_settings_are_rechecked_in_action(): void
    {
        [$cart] = $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload() + ['scheduled_for' => '2026-10-03T10:00:00Z'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('checkoutLimits')]
    public function test_guest_minimum_quantity_and_coordinates_policies_are_enforced(array $settings, string $type, int $quantity, bool $success): void
    {
        [$cart,$assignment] = $this->cart($quantity);
        $this->settings($settings);
        $payload = $this->payload($type);
        if ($type === 'delivery') {
            DeliveryZone::factory()->for($assignment->branch)->create(['minimum_order' => '5.00']);
            $payload['address'] = $this->address();
        }
        $response = $this->postJson('/api/v1/checkout', $payload);
        if ($success) {
            $response->assertCreated();
        } else {
            $response->assertUnprocessable();
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public static function checkoutLimits(): array
    {
        return [
            [['orders.allow_guest_checkout' => false], 'pickup', 1, false],
            [['orders.minimum_amount' => '11.00'], 'pickup', 1, false],
            [['orders.minimum_amount' => '10.00'], 'pickup', 1, true],
            [['pickup.minimum_amount' => '11.00'], 'pickup', 1, false],
            [['pickup.minimum_amount' => '10.00'], 'pickup', 1, true],
            [['orders.maximum_items_per_order' => 1], 'pickup', 2, false],
            [['orders.maximum_items_per_order' => 2], 'pickup', 2, true],
            [['orders.minimum_amount' => '11.00'], 'delivery', 1, false],
            [['orders.minimum_amount' => '10.00', 'pickup.minimum_amount' => '99.00'], 'delivery', 1, true],
            [['delivery.require_coordinates' => true], 'delivery', 1, false],
        ];
    }

    public function test_authenticated_checkout_allowed_when_guest_checkout_disabled(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->cart();
        $this->settings(['orders.allow_guest_checkout' => false]);
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
    }

    public function test_delivery_effective_minimum_is_maximum_of_zone_and_global_minimum(): void
    {
        [$cart,$assignment] = $this->cart();
        DeliveryZone::factory()->for($assignment->branch)->create(['minimum_order' => '11.00']);
        $this->settings(['orders.minimum_amount' => '5.00']);
        $this->postJson('/api/v1/checkout', $this->payload('delivery') + ['address' => $this->address()])
            ->assertUnprocessable()->assertJsonPath('errors.delivery.0.code', 'MINIMUM_ORDER_NOT_MET');
    }

    public function test_status_recomputes_immediately_after_hours_switches_and_closure_changes(): void
    {
        $branch = Branch::factory()->create();
        $url = '/api/v1/branches/'.$branch->uuid.'/status';
        $this->getJson($url)->assertOk()->assertJsonPath('data.is_open', true)->assertJsonPath('data.accepts_orders', true);
        $branch->update(['accepts_orders' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.is_open', true)->assertJsonPath('data.accepts_orders', false);
        $branch->update(['accepts_orders' => true]);
        $closure = BranchClosure::factory()->for($branch)->active()->create();
        $this->getJson($url)->assertOk()->assertJsonPath('data.reason.code', 'BRANCH_TEMPORARILY_CLOSED');
        $closure->delete();
        $branch->businessHours()->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('data.reason.code', 'BRANCH_CLOSED')->assertJsonPath('data.next_opening_at', null);
    }

    public function test_availability_uses_bounded_queries_and_never_queries_per_product(): void
    {
        [$cart,$assignment] = $this->cart();
        app(RestaurantSettingsService::class)->all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/menu?branch='.$assignment->branch->uuid)->assertOk();
        $before = count(DB::getQueryLog());
        for ($i = 0; $i < 8; $i++) {
            $product = Product::factory()->create(['category_id' => $assignment->product->category_id]);
            $assignment->branch->products()->attach($product);
        }
        DB::flushQueryLog();
        $this->getJson('/api/v1/menu?branch='.$assignment->branch->uuid)->assertOk();
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($before, $after);
    }

    public function test_checkout_rechecks_closure_created_during_repricing_and_rolls_back(): void
    {
        [$cart,$assignment] = $this->cart();
        $real = new PricingService;
        $created = false;
        $this->partialMock(PricingService::class, function ($mock) use ($real, $assignment, &$created): void {
            $mock->shouldReceive('calculate')->andReturnUsing(function (...$args) use ($real, $assignment, &$created) {
                if (! $created) {
                    BranchClosure::factory()->for($assignment->branch)->active()->create();
                    $created = true;
                }

                return $real->calculate(...$args);
            });
        });
        $this->postJson('/api/v1/checkout', $this->payload())->assertUnprocessable()
            ->assertJsonPath('errors.availability.0.code', 'BRANCH_TEMPORARILY_CLOSED');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('active', $cart->fresh()->status->value);
    }

    public function test_asap_rechecks_current_instant_when_repricing_crosses_closing_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02T11:59:59Z'));
        [$cart,$assignment] = $this->cart();
        app(ReplaceBusinessHoursAction::class)->handle($assignment->branch, [['day_of_week' => 5, 'opens_at' => '11:00', 'closes_at' => '15:00']]);
        $real = new PricingService;
        $this->partialMock(PricingService::class, function ($mock) use ($real): void {
            $mock->shouldReceive('calculate')->andReturnUsing(function (...$args) use ($real) {
                $this->travelTo(CarbonImmutable::parse('2026-10-02T12:00:00Z'));

                return $real->calculate(...$args);
            });
        });
        $this->postJson('/api/v1/checkout', $this->payload())->assertUnprocessable()->assertJsonPath('errors.availability.0.code', 'BRANCH_CLOSED');
        $this->assertDatabaseCount('orders', 0);
        $this->travelBack();
    }

    private function cart(int $quantity = 1): array
    {
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => '10.00']);
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-UUID', $response->json('data.id'));
        if ($response->json('data.token') !== null) {
            $this->withHeader('X-Cart-Token', $response->json('data.token'));
        }
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => $quantity])->assertCreated();

        return [Cart::where('uuid', $response->json('data.id'))->firstOrFail(), $assignment];
    }

    private function payload(string $type = 'pickup'): array
    {
        return ['type' => $type, 'customer' => ['name' => 'Customer', 'phone' => '0501234567'], 'payment_method' => 'cash'];
    }

    private function address(): array
    {
        return ['recipient_name' => 'Recipient', 'phone' => '0501234567', 'city' => 'الرياض', 'district' => 'المصيف',
            'street' => 'Street', 'latitude' => null, 'longitude' => null];
    }

    private function settings(array $values): void
    {
        app(UpdateRestaurantSettingsAction::class)->handle($values, User::factory()->create());
    }
}
