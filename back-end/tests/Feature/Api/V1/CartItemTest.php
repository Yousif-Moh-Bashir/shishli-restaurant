<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CartItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_prices_are_calculated_from_branch_and_options_in_exact_minor_units(): void
    {
        $branch = Branch::factory()->create();
        $cart = $this->guest($branch);
        $product = Product::factory()->create(['base_price' => '20.00']);
        $branch->products()->attach($product, ['price_override' => '19.99']);
        $group = OptionGroup::factory()->multiple()->create(['max_select' => 3]);
        $product->optionGroups()->attach($group);
        $first = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '1.10']);
        $second = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '1.40']);
        $this->postJson('/api/v1/cart/items', [
            'product_uuid' => $product->uuid, 'quantity' => 3,
            'options' => [$this->selection($group, [$first, $second])],
            'unit_price' => '0.01', 'base_price' => '0.00', 'options_total' => '0.00', 'total' => '0.01',
            'cart_id' => 999, 'product_id' => 999,
        ])->assertCreated()->assertJsonPath('data.items.0.pricing.base_price', '19.99')
            ->assertJsonPath('data.items.0.pricing.options_total', '2.50')->assertJsonPath('data.items.0.pricing.unit_price', '22.49')
            ->assertJsonPath('data.items.0.pricing.line_total', '67.47')->assertJsonPath('data.summary.subtotal', '67.47')
            ->assertJsonPath('data.summary.total', '67.47')->assertJsonPath('data.can_checkout', true)
            ->assertJsonCount(2, 'data.items.0.options')->assertJsonMissingPath('data.items.0.product_id')
            ->assertJsonMissingPath('data.items.0.configuration_hash');
        $other = Product::factory()->create(['base_price' => '5.00']);
        $branch->products()->attach($other);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $other->uuid, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.summary.total', '77.47')
            ->assertJsonPath('data.summary.items_count', 2)->assertJsonPath('data.summary.quantity_total', 5);
        $this->assertSame('77.47', $cart->fresh()->total);
        $this->assertDatabaseCount('cart_item_options', 2);
    }

    #[DataProvider('unavailableStates')]
    public function test_product_orderability_is_enforced(string $state): void
    {
        $branch = Branch::factory()->create();
        $this->guest($branch);
        $product = Product::factory()->create();
        if ($state !== 'unassigned') {
            $branch->products()->attach($product);
        }
        match ($state) {
            'global unavailable' => $product->update(['is_available' => false]),
            'inactive' => $product->update(['is_active' => false]),
            'deleted' => $product->delete(),
            'category inactive' => $product->category->update(['is_active' => false]),
            'category deleted' => $product->category->delete(),
            'local unavailable' => $branch->products()->updateExistingPivot($product->id, ['is_available' => false]),
            'closed branch' => $branch->update(['accepts_orders' => false]),
            'inactive branch' => $branch->update(['is_active' => false]),
            default => null,
        };
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame('0.00', Cart::first()->total);
    }

    public static function unavailableStates(): array
    {
        return [['unassigned'], ['global unavailable'], ['inactive'], ['deleted'], ['category inactive'],
            ['category deleted'], ['local unavailable'], ['closed branch'], ['inactive branch']];
    }

    #[DataProvider('badQuantities')]
    public function test_quantity_validation(int|string|null $quantity): void
    {
        $assignment = BranchProduct::factory()->create();
        $this->guest($assignment->branch);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => $quantity])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public static function badQuantities(): array
    {
        return [[0], [51], [-1], ['1.5'], [null]];
    }

    #[DataProvider('invalidSelections')]
    public function test_option_selection_integrity_is_enforced(string $case): void
    {
        $assignment = BranchProduct::factory()->create();
        $this->guest($assignment->branch);
        $product = $assignment->product;
        $group = OptionGroup::factory()->multiple()->create(['max_select' => 3]);
        $product->optionGroups()->attach($group);
        $values = OptionValue::factory()->count(4)->for($group, 'optionGroup')->create();
        $selections = [$this->selection($group, [$values[0]])];
        switch ($case) {
            case 'required missing':
                $group->update(['is_required' => true, 'min_select' => 1]);
                $values[0]->update(['is_default' => true]);
                $selections = [];
                break;
            case 'single exceeded':
                $group->update(['type' => 'single', 'max_select' => 1]);
                $selections = [$this->selection($group, [$values[0], $values[1]])];
                break;
            case 'multiple exceeded':
                $selections = [$this->selection($group, $values->all())];
                break;
            case 'below minimum':
                $group->update(['min_select' => 2]);
                break;
            case 'override minimum':
                $product->optionGroups()->updateExistingPivot($group->id, ['is_required_override' => true, 'min_select_override' => 2]);
                break;
            case 'override maximum':
                $product->optionGroups()->updateExistingPivot($group->id, ['max_select_override' => 1]);
                $selections = [$this->selection($group, [$values[0], $values[1]])];
                break;
            case 'foreign group':
                $product->optionGroups()->detach($group);
                break;
            case 'wrong value group':
                $selections = [$this->selection($group, [OptionValue::factory()->create()])];
                break;
            case 'inactive group':
                $group->update(['is_active' => false]);
                break;
            case 'deleted group':
                $group->delete();
                break;
            case 'inactive value':
                $values[0]->update(['is_active' => false]);
                break;
            case 'deleted value':
                $values[0]->delete();
                break;
            case 'duplicate group':
                $selections[] = $selections[0];
                break;
            case 'duplicate value':
                $selections = [$this->selection($group, [$values[0], $values[0]])];
                break;
        }
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1, 'options' => $selections])
            ->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('cart_item_options', 0);
    }

    public static function invalidSelections(): array
    {
        return array_map(fn (string $case): array => [$case], [
            'required missing', 'single exceeded', 'multiple exceeded', 'below minimum', 'override minimum', 'override maximum',
            'foreign group', 'wrong value group', 'inactive group', 'deleted group', 'inactive value', 'deleted value', 'duplicate group', 'duplicate value',
        ]);
    }

    public function test_duplicate_signatures_ignore_group_and_value_order_but_keep_different_choices_separate(): void
    {
        $assignment = BranchProduct::factory()->create();
        $this->guest($assignment->branch);
        $product = $assignment->product;
        $groups = OptionGroup::factory()->count(2)->multiple()->create();
        $product->optionGroups()->attach($groups->modelKeys());
        $values = OptionValue::factory()->count(2)->for($groups[0], 'optionGroup')->create();
        $value = OptionValue::factory()->for($groups[1], 'optionGroup')->create();
        $first = [$this->selection($groups[0], $values->all()), $this->selection($groups[1], [$value])];
        $second = [$this->selection($groups[1], [$value]), $this->selection($groups[0], $values->reverse()->all())];
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1, 'options' => $first])->assertCreated();
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 2, 'options' => $second])
            ->assertCreated()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 3);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1, 'options' => []])
            ->assertCreated()->assertJsonCount(2, 'data.items');
        $this->assertDatabaseCount('cart_item_options', 3);
    }

    public function test_merged_quantity_above_limit_is_rejected_without_changing_totals(): void
    {
        $assignment = BranchProduct::factory()->create();
        $this->guest($assignment->branch);
        $payload = ['product_uuid' => $assignment->product->uuid, 'quantity' => 50];
        $response = $this->postJson('/api/v1/cart/items', $payload)->assertCreated();
        $this->postJson('/api/v1/cart/items', array_replace($payload, ['quantity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(50, CartItem::first()->quantity);
        $this->assertSame($response->json('data.summary.total'), Cart::first()->total);
    }

    public function test_quantity_and_options_updates_reprice_and_merge_identical_lines(): void
    {
        $assignment = BranchProduct::factory()->withPriceOverride('20.00')->create();
        $this->guest($assignment->branch);
        $product = $assignment->product;
        $group = OptionGroup::factory()->create();
        $product->optionGroups()->attach($group);
        $large = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '5.00']);
        $plain = $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1])
            ->assertCreated()->json('data.items.0.id');
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 2, 'options' => [$this->selection($group, [$large])]])->assertCreated();
        $assignment->update(['price_override' => '22.00']);
        $this->patchJson('/api/v1/cart/items/'.$plain, ['quantity' => 3])->assertOk()
            ->assertJsonPath('data.items.0.pricing.unit_price', '22.00')->assertJsonPath('data.items.0.pricing.line_total', '66.00');
        $this->patchJson('/api/v1/cart/items/'.$plain.'/options', ['options' => [$this->selection($group, [$large])]])
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.items.0.pricing.unit_price', '27.00')->assertJsonPath('data.summary.total', '135.00');
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('cart_item_options', 1);
        $remaining = CartItem::first();
        $this->patchJson('/api/v1/cart/items/'.$remaining->uuid.'/options', ['options' => []])
            ->assertOk()->assertJsonPath('data.summary.total', '110.00')->assertJsonPath('data.items.0.options', []);
    }

    public function test_failed_options_merge_rolls_back_source_options_and_totals(): void
    {
        $assignment = BranchProduct::factory()->withPriceOverride('20.00')->create();
        $this->guest($assignment->branch);
        $product = $assignment->product;
        $group = OptionGroup::factory()->create();
        $product->optionGroups()->attach($group);
        $value = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '5.00']);
        $first = $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 30])
            ->assertCreated()->json('data.items.0.id');
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 30, 'options' => [$this->selection($group, [$value])]])->assertCreated();
        $this->patchJson('/api/v1/cart/items/'.$first.'/options', ['options' => [$this->selection($group, [$value])]])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertDatabaseCount('cart_item_options', 1);
        $this->assertSame('1350.00', Cart::first()->total);
    }

    #[DataProvider('itemMutations')]
    public function test_guest_cannot_mutate_item_in_another_cart(string $method, string $suffix, array $payload): void
    {
        $foreign = CartItem::factory()->create();
        $this->guest(Branch::factory()->create());
        $url = '/api/v1/cart/items/'.$foreign->uuid.$suffix;
        $this->json($method, $url, $payload)->assertNotFound();
        $this->assertSame(1, $foreign->fresh()->quantity);
        $this->withoutHeader('X-Cart-Token')->json($method, $url, $payload)->assertUnauthorized();
    }

    public static function itemMutations(): array
    {
        return [['PATCH', '', ['quantity' => 2]], ['PATCH', '/options', ['options' => []]], ['DELETE', '', []]];
    }

    public function test_authenticated_user_cannot_mutate_another_users_item(): void
    {
        $owner = User::factory()->create();
        $foreignCart = Cart::factory()->authenticated($owner)->create();
        $foreign = CartItem::factory()->for($foreignCart)->create();
        $user = User::factory()->create();
        $ownCart = Cart::factory()->authenticated($user)->create();
        $this->actingAs($user, 'sanctum')->withHeader('X-Cart-UUID', $ownCart->uuid)
            ->patchJson('/api/v1/cart/items/'.$foreign->uuid, ['quantity' => 2])->assertNotFound();
        $this->withHeader('X-Cart-UUID', $foreignCart->uuid)->deleteJson('/api/v1/cart/items/'.$foreign->uuid)->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_remove_cascades_options_and_clear_preserves_cart_and_resets_totals(): void
    {
        $assignment = BranchProduct::factory()->withPriceOverride('20.00')->create();
        $cart = $this->guest($assignment->branch);
        $product = $assignment->product;
        $group = OptionGroup::factory()->create();
        $product->optionGroups()->attach($group);
        $value = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '5.00']);
        $id = $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 1, 'options' => [$this->selection($group, [$value])]])
            ->assertCreated()->json('data.items.0.id');
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 2])->assertCreated();
        $this->deleteJson('/api/v1/cart/items/'.$id)->assertOk()->assertJsonPath('data.summary.total', '40.00');
        $this->assertDatabaseCount('cart_item_options', 0);
        $this->deleteJson('/api/v1/cart/items')->assertOk()->assertJsonPath('data.summary.total', '0.00')
            ->assertJsonPath('data.summary.subtotal', '0.00')->assertJsonPath('data.can_checkout', false)->assertJsonPath('data.items', []);
        $this->assertModelExists($cart);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_cart_total_overflow_rolls_back_the_new_line(): void
    {
        $branch = Branch::factory()->create();
        $cart = $this->guest($branch);
        $products = Product::factory()->count(2)->create(['base_price' => '99999999.99']);
        $branch->products()->attach($products->modelKeys());
        foreach ($products as $product) {
            $this->postJson('/api/v1/cart/items', ['product_uuid' => $product->uuid, 'quantity' => 50])->assertCreated();
        }
        $extra = Product::factory()->create(['base_price' => '1.00']);
        $branch->products()->attach($extra);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $extra->uuid, 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertSame('9999999999.00', $cart->fresh()->total);
    }

    private function guest(Branch $branch): Cart
    {
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $response->json('data.token'));

        return Cart::where('uuid', $response->json('data.id'))->firstOrFail();
    }

    private function selection(OptionGroup $group, array $values): array
    {
        return ['option_group_uuid' => $group->uuid, 'option_value_uuids' => array_map(fn (OptionValue $value): string => $value->uuid, $values)];
    }
}
