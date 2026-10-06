<?php

namespace Tests\Feature;

use App\Actions\Orders\PlaceOrderAction;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\RestaurantSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderTrackingEstimatesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function cart(?int $preparation = null, int $quantity = 1): Cart
    {
        $product = Product::factory()->create(['preparation_time' => $preparation, 'base_price' => '20.00']);
        $item = CartItem::factory()->for($product)->create(['quantity' => $quantity, 'line_total' => (string) ($quantity * 20)]);

        return $item->cart;
    }

    private function payload(string $type = 'pickup'): array
    {
        $data = ['type' => $type, 'payment_method' => 'cash', 'customer' => ['name' => 'عميل', 'phone' => '0501234567']];
        if ($type === 'delivery') {
            $data['address'] = ['recipient_name' => 'عميل', 'phone' => '0501234567', 'city' => 'الرياض', 'district' => 'المصيف', 'street' => 'شارع الوجهة', 'building_number' => '12'];
        }

        return $data;
    }

    private function setting(string $key, string $value, string $type = 'integer'): void
    {
        DB::table('restaurant_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'type' => $type, 'group' => 'orders', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()]);
        app(RestaurantSettingsService::class)->forget();
    }

    #[DataProvider('preparationPolicies')]
    public function test_preparation_uses_product_time_or_default_without_multiplying_quantity(?int $minutes, int $quantity, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03T10:00:00Z'));
        $order = app(PlaceOrderAction::class)->handle($this->cart($minutes, $quantity), $this->payload());
        $this->assertSame($expected, $order->estimated_ready_at->toISOString());
        $this->assertNull($order->estimated_delivery_from);
        $this->assertNull($order->estimated_delivery_to);
        $this->travelBack();
    }

    public static function preparationPolicies(): array
    {
        return [[null, 1, '2026-10-03T10:25:00.000000Z'], [0, 1, '2026-10-03T10:25:00.000000Z'],
            [15, 1, '2026-10-03T10:15:00.000000Z'], [15, 5, '2026-10-03T10:15:00.000000Z']];
    }

    public function test_multiple_products_use_maximum_known_preparation_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03T10:00:00Z'));
        $cart = $this->cart(10);
        foreach ([35, null] as $minutes) {
            $product = Product::factory()->create(['preparation_time' => $minutes, 'base_price' => '20.00']);
            CartItem::factory()->for($cart)->for($product)->create();
        }
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload());
        $this->assertSame('2026-10-03T10:35:00.000000Z', $order->estimated_ready_at->toISOString());
        $this->travelBack();
    }

    public function test_delivery_uses_default_preparation_and_preserves_delivery_range(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03T10:00:00Z'));
        $cart = $this->cart();
        DeliveryZone::factory()->for($cart->branch)->create(['estimated_min_minutes' => 20, 'estimated_max_minutes' => 45]);
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload('delivery'));
        $this->assertSame('2026-10-03T10:40:00.000000Z', $order->estimated_ready_at->toISOString());
        $this->assertSame('2026-10-03T11:00:00.000000Z', $order->estimated_delivery_from->toISOString());
        $this->assertSame('2026-10-03T11:25:00.000000Z', $order->estimated_delivery_to->toISOString());
        $this->travelBack();
    }

    public function test_missing_delivery_eta_remains_null_instead_of_inventing_a_time(): void
    {
        $cart = $this->cart();
        DeliveryZone::factory()->for($cart->branch)->create(['estimated_min_minutes' => null, 'estimated_max_minutes' => null]);
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload('delivery'));
        $this->assertNotNull($order->estimated_ready_at);
        $this->assertNull($order->estimated_delivery_from);
        $this->assertNull($order->estimated_delivery_to);
    }

    public function test_scheduled_order_estimate_uses_scheduled_time_and_guest_expiry_uses_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03T10:00:00Z'));
        $this->setting('orders.allow_scheduled_orders', '1', 'boolean');
        $cart = $this->cart(15);
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload() + ['order_timing' => 'scheduled', 'scheduled_for' => '2026-10-04T12:00:00Z']);
        $this->assertSame('2026-10-04T12:15:00.000000Z', $order->estimated_ready_at->toISOString());
        $this->assertSame('2026-11-02T10:00:00.000000Z', $order->guest_access_token_expires_at->toISOString());
        $this->travelBack();
    }

    public function test_estimates_items_address_and_totals_are_snapshots_when_catalog_settings_and_zones_change(): void
    {
        $cart = $this->cart(15);
        $product = $cart->items()->first()->product;
        $zone = DeliveryZone::factory()->for($cart->branch)->create();
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload('delivery'));
        $payload = ['order_number' => $order->order_number, 'token' => $order->issuedGuestAccessToken];
        $first = $this->postJson('/api/v1/orders/track', $payload)->assertOk();
        $product->update(['name' => 'اسم جديد', 'base_price' => '200.00', 'preparation_time' => 120]);
        $zone->update(['estimated_min_minutes' => 100, 'estimated_max_minutes' => 200, 'delivery_fee' => '100.00']);
        $this->setting('delivery.default_preparation_minutes', '200');
        $second = $this->postJson('/api/v1/orders/track', $payload)->assertOk();
        foreach (['estimates', 'items', 'summary', 'destination'] as $section) {
            $this->assertSame($first->json('data.'.$section), $second->json('data.'.$section));
        }
        $this->assertSame('شارع الوجهة', $second->json('data.destination.street'));
        $this->assertSame($order->total, $second->json('data.summary.total'));
    }

    public function test_saved_customer_address_changes_do_not_change_tracking_destination(): void
    {
        $user = User::factory()->create();
        $cart = $this->cart(15);
        $cart->update(['user_id' => $user->id]);
        $address = CustomerAddress::factory()->for($user)->create(['street' => 'العنوان الأصلي']);
        DeliveryZone::factory()->for($cart->branch)->create();
        $payload = $this->payload('delivery');
        unset($payload['address']);
        $payload['address_uuid'] = $address->uuid;
        $order = app(PlaceOrderAction::class)->handle($cart, $payload);
        $address->update(['street' => 'العنوان المعدل']);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders/'.$order->uuid.'/tracking')
            ->assertOk()->assertJsonPath('data.destination.street', 'العنوان الأصلي');
        $this->assertNull($order->guest_access_token_hash);
    }

    public function test_legacy_order_without_estimate_snapshot_returns_null(): void
    {
        $order = Order::factory()->create(['access_token' => str_repeat('a', 64)]);
        $this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'token' => str_repeat('a', 64)])->assertOk()
            ->assertJsonPath('data.estimates.ready_at', null)->assertJsonPath('data.estimates.delivery_from', null);
    }
}
