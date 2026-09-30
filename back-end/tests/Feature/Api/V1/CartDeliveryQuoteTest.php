<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CartDeliveryQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_quote_uses_cart_branch_and_subtotal_without_mutating_totals(): void
    {
        $branch = Branch::factory()->create();
        $zone = DeliveryZone::factory()->for($branch)->create(['free_delivery_threshold' => '100.00']);
        $cart = $this->guestCart($branch);
        $other = DeliveryZone::factory()->create(['delivery_fee' => '0.00', 'priority' => 999]);
        $this->postJson('/api/v1/cart/delivery/quote', ['address' => $this->address(), 'branch_uuid' => $other->branch->uuid,
            'subtotal' => '999.00', 'delivery_fee' => '0.00', 'zone_uuid' => $other->uuid, 'estimated_total' => '0.00'])
            ->assertOk()->assertJsonPath('data.zone.id', $zone->uuid)->assertJsonPath('data.subtotal', '80.00')
            ->assertJsonPath('data.delivery_fee', '5.00')->assertJsonPath('data.estimated_total', '85.00')
            ->assertJsonPath('data.free_delivery_applied', false);
        $this->assertSame('80.00', $cart->fresh()->total);
        $this->assertSame('80.00', $cart->fresh()->subtotal);
    }

    public function test_authenticated_quote_accepts_only_own_saved_address(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->authenticated($user)->create(['subtotal' => '80.00', 'total' => '80.00']);
        DeliveryZone::factory()->for($cart->branch)->create();
        $own = CustomerAddress::factory()->forUser($user)->create();
        $foreign = CustomerAddress::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cart/delivery/quote', ['address_uuid' => $own->uuid])
            ->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.estimated_total', '85.00');
        $this->postJson('/api/v1/cart/delivery/quote', ['address_uuid' => $foreign->uuid])->assertNotFound();
        $own->delete();
        $this->postJson('/api/v1/cart/delivery/quote', ['address_uuid' => $own->uuid])->assertNotFound();
    }

    public function test_guest_cannot_use_saved_address_or_access_cart_without_token(): void
    {
        $this->guestCart(Branch::factory()->create());
        $address = CustomerAddress::factory()->create();
        $this->postJson('/api/v1/cart/delivery/quote', ['address_uuid' => $address->uuid])->assertNotFound();
        $this->withoutHeader('X-Cart-Token')->postJson('/api/v1/cart/delivery/quote', ['address' => $this->address()])->assertUnauthorized();
    }

    #[DataProvider('issues')]
    public function test_cart_quote_returns_business_issues(string $scenario, string $code): void
    {
        $branch = Branch::factory()->create();
        $zone = DeliveryZone::factory()->for($branch)->create();
        $cart = $this->guestCart($branch);
        $address = $this->address();
        switch ($scenario) {
            case 'outside': $address['district'] = 'بعيد';
                break;
            case 'minimum': $zone->update(['minimum_order' => '90.00']);
                break;
            case 'coordinates':
                $zone->delete();
                DeliveryZone::factory()->for($branch)->radius()->create();
                break;
            case 'closed': $branch->update(['accepts_orders' => false]);
                break;
        }
        $this->postJson('/api/v1/cart/delivery/quote', ['address' => $address])->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonPath('data.issue.code', $code);
        $this->assertSame('80.00', $cart->fresh()->total);
    }

    public static function issues(): array
    {
        return [['outside', 'ADDRESS_OUTSIDE_DELIVERY_AREA'], ['minimum', 'MINIMUM_ORDER_NOT_MET'],
            ['coordinates', 'ADDRESS_COORDINATES_REQUIRED'], ['closed', 'BRANCH_NOT_ACCEPTING_ORDERS']];
    }

    public function test_quote_requires_exactly_one_address_source(): void
    {
        $this->guestCart(Branch::factory()->create());
        $this->postJson('/api/v1/cart/delivery/quote', [])->assertUnprocessable();
        $this->postJson('/api/v1/cart/delivery/quote', ['address' => []])->assertUnprocessable();
        $this->postJson('/api/v1/cart/delivery/quote', ['address' => $this->address(), 'address_uuid' => CustomerAddress::factory()->create()->uuid])->assertUnprocessable();
    }

    public function test_quote_rejects_expired_guest_cart_and_foreign_authenticated_cart(): void
    {
        $cart = $this->guestCart(Branch::factory()->create());
        $cart->update(['expires_at' => now()->subDay()]);
        $this->postJson('/api/v1/cart/delivery/quote', ['address' => $this->address()])->assertNotFound();
        $other = Cart::factory()->authenticated(User::factory()->create())->create();
        $this->withoutHeader('X-Cart-Token')->actingAs(User::factory()->create(), 'sanctum')
            ->withHeader('X-Cart-UUID', $other->uuid)
            ->postJson('/api/v1/cart/delivery/quote', ['address' => $this->address()])->assertNotFound();
    }

    private function guestCart(Branch $branch): Cart
    {
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $response->json('data.token'));
        $product = Product::factory()->create(['base_price' => '40.00']);
        $branch->products()->attach($product);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 2])->assertCreated();

        return Cart::where('uuid', $response->json('data.id'))->firstOrFail();
    }

    private function address(): array
    {
        return ['city' => 'الرياض', 'district' => 'المصيف'];
    }
}
