<?php

namespace Tests\Feature\Api\V1;

use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CartRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_updates_prices_but_preserves_option_name_snapshots_and_get_does_not_reprice(): void
    {
        [$cart, $assignment, $group, $value] = $this->configuredCart();
        $assignment->update(['price_override' => '25.00']);
        $group->update(['name' => 'اسم جديد للمجموعة']);
        $value->update(['name' => 'اسم جديد للخيار', 'price_modifier' => '7.00']);

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.summary.total', '50.00');
        $this->assertSame('50.00', $cart->fresh()->total);
        $this->postJson('/api/v1/cart/refresh')->assertOk()
            ->assertJsonPath('data.items.0.pricing.base_price', '25.00')
            ->assertJsonPath('data.items.0.pricing.options_total', '7.00')
            ->assertJsonPath('data.items.0.pricing.unit_price', '32.00')
            ->assertJsonPath('data.items.0.pricing.line_total', '64.00')
            ->assertJsonPath('data.items.0.options.0.group.name', 'الحجم')
            ->assertJsonPath('data.items.0.options.0.value.name', 'كبير')
            ->assertJsonPath('data.items.0.options.0.price_modifier', '7.00')
            ->assertJsonPath('data.summary.total', '64.00')
            ->assertJsonPath('data.can_checkout', true);
        $this->assertSame('64.00', $cart->fresh()->total);
        $this->assertSame('7.00', $cart->items()->first()->options()->first()->price_modifier);
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonPath('data.summary.total', '64.00');
    }

    public function test_refresh_uses_current_base_price_when_branch_override_is_removed(): void
    {
        [$cart, $assignment] = $this->configuredCart();
        $assignment->update(['price_override' => null]);
        $assignment->product->update(['base_price' => '19.99']);

        $this->postJson('/api/v1/cart/refresh')->assertOk()
            ->assertJsonPath('data.items.0.pricing.base_price', '19.99')
            ->assertJsonPath('data.summary.total', '49.98');
        $this->assertSame('49.98', $cart->fresh()->total);
    }

    #[DataProvider('unavailableItems')]
    public function test_refresh_preserves_invalid_items_and_reports_the_reason(string $change, string $code): void
    {
        [$cart, $assignment, $group, $value] = $this->configuredCart();
        match ($change) {
            'product inactive' => $assignment->product->update(['is_active' => false]),
            'product unavailable' => $assignment->product->update(['is_available' => false]),
            'product deleted' => $assignment->product->delete(),
            'category inactive' => $assignment->product->category->update(['is_active' => false]),
            'category deleted' => $assignment->product->category->delete(),
            'branch product unavailable' => $assignment->update(['is_available' => false]),
            'product detached' => $assignment->delete(),
            'group inactive' => $group->update(['is_active' => false]),
            'group deleted' => $group->delete(),
            'group detached' => $assignment->product->optionGroups()->detach($group),
            'value inactive' => $value->update(['is_active' => false]),
            'value deleted' => $value->delete(),
            'minimum changed' => $assignment->product->optionGroups()->updateExistingPivot($group->id, ['min_select_override' => 2]),
        };

        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.is_available', false)
            ->assertJsonPath('data.items.0.issues.0.code', $code)
            ->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.summary.total', '50.00');
        $this->assertSame('50.00', $cart->fresh()->total);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('cart_item_options', 1);
    }

    public static function unavailableItems(): array
    {
        return [
            ['product inactive', 'PRODUCT_UNAVAILABLE'],
            ['product unavailable', 'PRODUCT_UNAVAILABLE'],
            ['product deleted', 'PRODUCT_UNAVAILABLE'],
            ['category inactive', 'PRODUCT_UNAVAILABLE'],
            ['category deleted', 'PRODUCT_UNAVAILABLE'],
            ['branch product unavailable', 'PRODUCT_UNAVAILABLE'],
            ['product detached', 'PRODUCT_NOT_IN_BRANCH'],
            ['group inactive', 'OPTION_UNAVAILABLE'],
            ['group deleted', 'OPTION_UNAVAILABLE'],
            ['group detached', 'OPTION_UNAVAILABLE'],
            ['value inactive', 'OPTION_UNAVAILABLE'],
            ['value deleted', 'OPTION_UNAVAILABLE'],
            ['minimum changed', 'OPTIONS_CONFIGURATION_CHANGED'],
        ];
    }

    public function test_new_required_group_does_not_automatically_select_a_default(): void
    {
        [$cart, $assignment] = $this->configuredCart();
        $required = OptionGroup::factory()->create(['is_required' => true, 'min_select' => 1]);
        $assignment->product->optionGroups()->attach($required);
        OptionValue::factory()->for($required, 'optionGroup')->create(['is_default' => true]);

        $this->postJson('/api/v1/cart/refresh')->assertOk()
            ->assertJsonPath('data.items.0.issues.0.code', 'OPTIONS_CONFIGURATION_CHANGED')
            ->assertJsonPath('data.can_checkout', false);
        $this->assertDatabaseCount('cart_item_options', 1);
        $this->assertSame('50.00', $cart->fresh()->total);
    }

    #[DataProvider('closedBranches')]
    public function test_closed_branch_cart_remains_readable_and_can_be_cleared(string $change): void
    {
        [$cart, $assignment] = $this->configuredCart();
        match ($change) {
            'closed' => $assignment->branch->update(['accepts_orders' => false]),
            'inactive' => $assignment->branch->update(['is_active' => false]),
            'deleted' => $assignment->branch->delete(),
        };
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.can_checkout', false);
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.issues.0.code', 'BRANCH_NOT_ACCEPTING_ORDERS')
            ->assertJsonPath('data.can_checkout', false);
        $item = $cart->items()->first();
        $this->patchJson('/api/v1/cart/items/'.$item->uuid, ['quantity' => 3])->assertUnprocessable();
        $this->assertSame(2, $item->fresh()->quantity);
        $this->deleteJson('/api/v1/cart/items')->assertOk()->assertJsonPath('data.summary.total', '0.00');
        $this->assertModelExists($cart);
    }

    public static function closedBranches(): array
    {
        return [['closed'], ['inactive'], ['deleted']];
    }

    public function test_valid_lines_reprice_while_invalid_lines_keep_their_snapshots(): void
    {
        [$cart, $assignment, , $value] = $this->configuredCart();
        $product = Product::factory()->create(['base_price' => '10.00']);
        $assignment->branch->products()->attach($product);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1])->assertCreated();
        $product->update(['base_price' => '12.00']);
        $value->update(['is_active' => false]);

        $this->postJson('/api/v1/cart/refresh')->assertOk()
            ->assertJsonPath('data.items.0.pricing.line_total', '50.00')
            ->assertJsonPath('data.items.1.pricing.line_total', '12.00')
            ->assertJsonPath('data.summary.total', '62.00')->assertJsonPath('data.can_checkout', false);
        $this->assertSame('62.00', $cart->fresh()->total);
    }

    public function test_aggregate_price_overflow_reports_issue_without_partially_repricing(): void
    {
        [$cart, $assignment] = $this->configuredCart();
        $this->patchJson('/api/v1/cart/items/'.$cart->items()->first()->uuid, ['quantity' => 50])->assertOk();
        $product = Product::factory()->create(['base_price' => '1.00']);
        $assignment->branch->products()->attach($product);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 50])->assertCreated();
        $assignment->update(['price_override' => '99999999.99']);
        $product->update(['base_price' => '99999999.99']);

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.issues.0.code', 'PRICE_LIMIT_EXCEEDED');
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.issues.0.code', 'PRICE_LIMIT_EXCEEDED')
            ->assertJsonPath('data.summary.total', '1300.00');
        $this->assertSame('1300.00', $cart->fresh()->total);
        $this->assertSame('25.00', $cart->items()->first()->unit_price);
    }

    public function test_empty_refresh_has_zero_totals_and_is_not_ready(): void
    {
        [$cart] = $this->configuredCart();
        $this->deleteJson('/api/v1/cart/items')->assertOk();
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonPath('data.summary.total', '0.00')
            ->assertJsonPath('data.summary.subtotal', '0.00')->assertJsonPath('data.can_checkout', false);
        $this->assertSame('0.00', $cart->fresh()->total);
    }

    public function test_cart_reads_have_bounded_queries_as_items_grow(): void
    {
        [$cart, $assignment] = $this->configuredCart();
        DB::enableQueryLog();
        $this->getJson('/api/v1/cart')->assertOk();
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $products = Product::factory()->count(5)->create();
        foreach ($products as $product) {
            $assignment->branch->products()->attach($product);
            $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1])->assertCreated();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(6, 'data.items');
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($smallCount + 1, $largeCount);
        $this->assertSame(6, $cart->items()->count());
    }

    /** @return array{Cart, BranchProduct, OptionGroup, OptionValue} */
    private function configuredCart(): array
    {
        $assignment = BranchProduct::factory()->withPriceOverride('20.00')->create();
        $group = OptionGroup::factory()->create(['name' => 'الحجم']);
        $assignment->product->optionGroups()->attach($group);
        $value = OptionValue::factory()->for($group, 'optionGroup')->create(['name' => 'كبير', 'price_modifier' => '5.00']);
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $response->json('data.token'));
        $this->postJson('/api/v1/cart/items', [
            'product_uuid' => $assignment->product->uuid, 'quantity' => 2,
            'options' => [['option_group_uuid' => $group->uuid, 'option_value_uuids' => [$value->uuid]]],
        ])->assertCreated();

        return [Cart::where('uuid', $response->json('data.id'))->firstOrFail(), $assignment, $group, $value];
    }

    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
