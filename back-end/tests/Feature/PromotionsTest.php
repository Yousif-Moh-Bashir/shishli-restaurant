<?php

namespace Tests\Feature;

use App\Actions\Orders\PlaceOrderAction;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\DeliveryZone;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use App\Services\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromotionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function promotionData(array $overrides = []): array
    {
        return array_replace(['name' => 'Lunch offer', 'type' => 'automatic', 'application_type' => 'cart', 'discount_type' => 'percentage', 'discount_value' => '20.00'], $overrides);
    }

    private function cart(string $price = '100.00', int $quantity = 1, ?User $user = null): array
    {
        if ($user !== null) {
            $this->actingAs($user, 'sanctum');
        }
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => $price]);
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withoutHeader('X-Cart-Token');
        if ($response->json('data.token') !== null) {
            $this->withHeader('X-Cart-Token', $response->json('data.token'));
        }
        $this->withHeader('X-Cart-UUID', $response->json('data.id'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => $quantity])->assertCreated();

        return [Cart::where('uuid', $response->json('data.id'))->firstOrFail(), $assignment];
    }

    private function checkout(array $extra = []): array
    {
        return array_replace(['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'Customer', 'phone' => '0501234567']], $extra);
    }

    #[DataProvider('adminEndpoints')]
    public function test_admin_endpoints_require_authentication_and_permission(string $method, string $path): void
    {
        $promotion = Promotion::factory()->coupon()->create();
        $coupon = Coupon::factory()->for($promotion)->create();
        $path = str_replace(['PROMOTION', 'COUPON'], [$promotion->uuid, $coupon->uuid], $path);
        $this->json($method, $path)->assertUnauthorized();
        $this->seed(RolePermissionSeeder::class);
        foreach (['customer', 'cashier', 'kitchen'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role), 'sanctum')->json($method, $path)->assertForbidden();
        }
    }

    public static function adminEndpoints(): array
    {
        $base = '/api/v1/admin/promotions';

        return [['GET', $base], ['POST', $base], ['GET', $base.'/PROMOTION'], ['PATCH', $base.'/PROMOTION'], ['DELETE', $base.'/PROMOTION'],
            ['GET', $base.'/PROMOTION/coupons'], ['POST', $base.'/PROMOTION/coupons'], ['PATCH', $base.'/PROMOTION/coupons/COUPON'], ['DELETE', $base.'/PROMOTION/coupons/COUPON']];
    }

    #[DataProvider('validDiscounts')]
    public function test_admin_creates_each_discount_type(string $type, string $application, string $value): void
    {
        $this->admin();
        $targets = $application === 'product' ? ['products' => [Product::factory()->create()->uuid]] : [];
        $response = $this->postJson('/api/v1/admin/promotions', $this->promotionData(['discount_type' => $type, 'application_type' => $application, 'discount_value' => $value] + $targets))->assertCreated();
        $response->assertJsonPath('data.discount.type', $type)->assertJsonMissingPath('data.db_id');
        $this->getJson('/api/v1/admin/promotions/'.$response->json('data.id'))->assertOk();
        $this->getJson('/api/v1/admin/promotions')->assertOk()->assertJsonCount(1, 'data');
    }

    public static function validDiscounts(): array
    {
        return [['percentage', 'cart', '20'], ['fixed', 'cart', '10'], ['fixed_price', 'product', '0']];
    }

    #[DataProvider('invalidPromotions')]
    public function test_admin_rejects_invalid_configurations(array $overrides, string $field): void
    {
        $this->admin();
        $this->postJson('/api/v1/admin/promotions', $this->promotionData($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('promotions', 0);
    }

    public static function invalidPromotions(): array
    {
        return [
            [['discount_value' => '101'], 'discount_value'], [['discount_value' => '0'], 'discount_value'],
            [['discount_type' => 'fixed', 'discount_value' => '0'], 'discount_value'],
            [['discount_value' => '-1'], 'discount_value'], [['discount_value' => '1.001'], 'discount_value'],
            [['discount_value' => '10000000000'], 'discount_value'], [['maximum_discount' => '-1'], 'maximum_discount'],
            [['minimum_subtotal' => '-1'], 'minimum_subtotal'], [['priority' => -1], 'priority'],
            [['usage_limit' => 0], 'usage_limit'], [['usage_limit_per_user' => 0], 'usage_limit_per_user'],
            [['application_type' => 'product'], 'products'], [['application_type' => 'category'], 'categories'],
            [['discount_type' => 'fixed_price'], 'discount_type'], [['type' => 'bad'], 'type'],
            [['starts_at' => '2030-01-02', 'ends_at' => '2030-01-01'], 'ends_at'],
            [['starts_at' => '2030-01-01', 'ends_at' => '2030-01-01'], 'ends_at'],
            [['branches' => ['bad']], 'branches.0'], [['products' => ['bad']], 'products.0'],
            [['name' => str_repeat('x', 181)], 'name'],
        ];
    }

    public function test_patch_validates_effective_state_and_syncs_targets_atomically(): void
    {
        $this->admin();
        $product = Product::factory()->create();
        $category = Category::factory()->create();
        $branch = Branch::factory()->create();
        $response = $this->postJson('/api/v1/admin/promotions', $this->promotionData(['application_type' => 'product', 'products' => [$product->uuid], 'branches' => [$branch->uuid], 'starts_at' => '2030-01-01', 'ends_at' => '2030-02-01']))->assertCreated();
        $url = '/api/v1/admin/promotions/'.$response->json('data.id');
        $this->patchJson($url, ['name' => 'Updated'])->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonCount(1, 'data.products');
        $this->patchJson($url, ['application_type' => 'category'])->assertUnprocessable()->assertJsonValidationErrors('categories');
        $this->patchJson($url, ['starts_at' => '2030-03-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->patchJson($url, ['discount_value' => '101'])->assertUnprocessable()->assertJsonValidationErrors('discount_value');
        $this->patchJson($url, ['application_type' => 'category', 'categories' => [$category->uuid], 'branches' => []])->assertOk()->assertJsonCount(0, 'data.products')->assertJsonCount(1, 'data.categories')->assertJsonCount(0, 'data.branches');
        $this->assertDatabaseCount('promotion_products', 0);
        $this->assertDatabaseCount('promotion_categories', 1);
        $this->deleteJson($url)->assertOk();
        $this->assertSoftDeleted('promotions', ['uuid' => $response->json('data.id')]);
        $this->getJson($url)->assertNotFound();
    }

    public function test_targets_must_be_unambiguous_distinct_and_existing(): void
    {
        $this->admin();
        $product = Product::factory()->create();
        $this->postJson('/api/v1/admin/promotions', $this->promotionData(['products' => [$product->uuid]]))->assertUnprocessable()->assertJsonValidationErrors('products');
        $this->postJson('/api/v1/admin/promotions', $this->promotionData(['application_type' => 'product', 'products' => [$product->uuid, $product->uuid]]))->assertUnprocessable()->assertJsonValidationErrors('products.0');
        $product->delete();
        $this->postJson('/api/v1/admin/promotions', $this->promotionData(['application_type' => 'product', 'products' => [$product->uuid]]))->assertUnprocessable()->assertJsonValidationErrors('products.0');
    }

    public function test_coupon_crud_normalization_uniqueness_partial_dates_and_scoping(): void
    {
        $this->admin();
        $promotion = Promotion::factory()->coupon()->create();
        $other = Promotion::factory()->coupon()->create();
        $url = '/api/v1/admin/promotions/'.$promotion->uuid.'/coupons';
        $response = $this->postJson($url, ['code' => '  shishli20  ', 'starts_at' => '2030-01-01', 'ends_at' => '2030-02-01'])->assertCreated()->assertJsonPath('data.code', 'SHISHLI20');
        $id = $response->json('data.id');
        $this->postJson($url, ['code' => 'ShIsHlI20'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson($url.'/'.$id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson($url.'/'.$id, ['starts_at' => '2030-03-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->patchJson('/api/v1/admin/promotions/'.$other->uuid.'/coupons/'.$id, ['code' => 'OTHER'])->assertNotFound();
        $this->deleteJson('/api/v1/admin/promotions/'.$other->uuid.'/coupons/'.$id)->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/admin/promotions/'.$promotion->uuid, ['type' => 'automatic'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->deleteJson($url.'/'.$id)->assertOk();
        $this->assertSoftDeleted('coupons', ['uuid' => $id]);
        $this->postJson($url, ['code' => 'SHISHLI20'])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidCoupons')]
    public function test_coupon_validation(array $data, string $field): void
    {
        $this->admin();
        $promotion = Promotion::factory()->coupon()->create();
        $this->postJson('/api/v1/admin/promotions/'.$promotion->uuid.'/coupons', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidCoupons(): array
    {
        return [[['code' => 'X', 'usage_limit' => 0], 'usage_limit'], [['code' => 'X', 'usage_limit_per_user' => 0], 'usage_limit_per_user'],
            [['code' => 'X', 'starts_at' => '2030-01-02', 'ends_at' => '2030-01-01'], 'ends_at'], [['code' => '  '], 'code'], [['code' => str_repeat('a', 65)], 'code'], [['code' => 'BAD CODE'], 'code']];
    }

    public function test_coupon_cannot_attach_to_automatic_promotion(): void
    {
        $this->admin();
        $promotion = Promotion::factory()->create();
        $this->postJson('/api/v1/admin/promotions/'.$promotion->uuid.'/coupons', ['code' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('promotion');
    }

    #[DataProvider('automaticIneligibility')]
    public function test_automatic_eligibility(array $attributes, string $target): void
    {
        [$cart,$assignment] = $this->cart();
        $promotion = Promotion::factory()->create($attributes);
        match ($target) {
            'branch' => $promotion->branches()->attach(Branch::factory()->create()),
            'product' => $promotion->products()->attach(Product::factory()->create()),
            'category' => $promotion->categories()->attach(Category::factory()->create()),
            default => null,
        };
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonPath('data.summary.discount_total', '0.00');
    }

    public static function automaticIneligibility(): array
    {
        return [
            [['is_active' => false], ''], [['starts_at' => now()->addDay()], ''], [['ends_at' => now()->subDay()], ''],
            [['minimum_subtotal' => '100.01'], ''], [[], 'branch'],
            [['application_type' => 'product'], 'product'], [['application_type' => 'category'], 'category'],
        ];
    }

    #[DataProvider('calculations')]
    public function test_discount_math_and_targets(string $price, int $qty, string $application, string $type, string $value, ?string $cap, string $expected): void
    {
        [$cart,$assignment] = $this->cart($price, $qty);
        $promotion = Promotion::factory()->create(['application_type' => $application, 'discount_type' => $type, 'discount_value' => $value, 'maximum_discount' => $cap]);
        if ($application === 'product') {
            $promotion->products()->attach($assignment->product);
        }
        if ($application === 'category') {
            $promotion->categories()->attach($assignment->product->category);
        }
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonPath('data.summary.discount_total', $expected)
            ->assertJsonPath('data.items.0.pricing.discount_total', $expected)->assertJsonPath('data.summary.subtotal', Money::decimal(Money::minor($price) * $qty));
        $this->postJson('/api/v1/checkout', $this->checkout())->assertCreated()->assertJsonPath('data.pricing.discount_total', $expected)
            ->assertJsonPath('data.items.0.pricing.discount_total', $expected);
    }

    public static function calculations(): array
    {
        return [
            ['100.00', 1, 'cart', 'percentage', '20', null, '20.00'], ['100.00', 2, 'cart', 'percentage', '20', '30', '30.00'],
            ['100.00', 1, 'cart', 'percentage', '100', null, '100.00'], ['0.03', 1, 'cart', 'percentage', '50', null, '0.02'],
            ['100.00', 2, 'cart', 'fixed', '10', null, '10.00'], ['3.00', 2, 'cart', 'fixed', '10', null, '6.00'],
            ['20.00', 3, 'product', 'fixed', '5', null, '15.00'], ['3.00', 3, 'product', 'fixed', '5', null, '9.00'],
            ['20.00', 3, 'product', 'percentage', '20', null, '12.00'], ['20.00', 3, 'category', 'percentage', '20', null, '12.00'],
            ['30.00', 3, 'product', 'fixed_price', '24', null, '18.00'], ['20.00', 3, 'product', 'fixed_price', '24', null, '0.00'],
            ['20.00', 3, 'category', 'fixed_price', '0', null, '60.00'], ['19.99', 3, 'product', 'percentage', '12.34', null, '7.41'],
        ];
    }

    public function test_product_discounts_exclude_options_and_other_products_and_stacking_never_eats_options(): void
    {
        [$cart,$assignment] = $this->cart('20.00', 3);
        $group = OptionGroup::factory()->create();
        $assignment->product->optionGroups()->attach($group);
        $option = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '5.00']);
        $this->patchJson('/api/v1/cart/items/'.$cart->items()->first()->uuid.'/options', ['options' => [['option_group_uuid' => $group->uuid, 'option_value_uuids' => [$option->uuid]]]])->assertOk();
        $other = Product::factory()->create(['base_price' => '10.00']);
        $assignment->branch->products()->attach($other);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $other->uuid, 'quantity' => 1])->assertCreated();
        $promotion = Promotion::factory()->stackable()->create(['application_type' => 'product']);
        $promotion->products()->attach($assignment->product);
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonPath('data.summary.subtotal', '85.00')->assertJsonPath('data.summary.discount_total', '12.00')
            ->assertJsonPath('data.items.0.pricing.unit_price', '25.00')->assertJsonPath('data.items.0.pricing.line_total_after_discount', '63.00')->assertJsonPath('data.items.1.pricing.discount_total', '0.00');
        $promotion->update(['discount_value' => '100']);
        $second = Promotion::factory()->stackable()->create(['application_type' => 'product', 'discount_value' => '100']);
        $second->products()->attach($assignment->product);
        $this->postJson('/api/v1/cart/refresh')->assertOk()->assertJsonPath('data.summary.discount_total', '60.00')->assertJsonPath('data.items.0.pricing.line_total_after_discount', '15.00');
    }

    public function test_stacking_priority_and_coupon_follow_the_same_rules(): void
    {
        [$cart] = $this->cart();
        $high = Promotion::factory()->fixed()->create(['discount_value' => '10', 'priority' => 100]);
        $low = Promotion::factory()->fixed()->stackable()->create(['discount_value' => '20', 'priority' => 50]);
        $coupon = Coupon::factory()->create();
        $coupon->promotion->update(['discount_type' => 'fixed', 'discount_value' => '30', 'is_stackable' => true, 'priority' => 10]);
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertUnprocessable()->assertJsonPath('errors.coupon.0.code', 'COUPON_NOT_APPLICABLE');
        $this->postJson('/api/v1/cart/refresh')->assertJsonPath('data.summary.discount_total', '10.00')->assertJsonCount(1, 'data.promotions');
        $high->update(['is_stackable' => true]);
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk()->assertJsonPath('data.summary.discount_total', '60.00')->assertJsonCount(3, 'data.promotions');
        $high->update(['discount_value' => '90']);
        $this->postJson('/api/v1/cart/refresh')->assertJsonPath('data.summary.discount_total', '100.00')->assertJsonPath('data.summary.total', '0.00');
    }

    public function test_tie_breaker_is_uuid_and_nonstackable_lower_priority_is_skipped(): void
    {
        [$cart] = $this->cart();
        $a = Promotion::factory()->fixed()->create(['discount_value' => '10']);
        $b = Promotion::factory()->fixed()->create(['discount_value' => '20']);
        $expected = strcmp($a->uuid, $b->uuid) < 0 ? $a : $b;
        $this->postJson('/api/v1/cart/refresh')->assertJsonPath('data.promotions.0.id', $expected->uuid);
        $expected->update(['is_stackable' => true, 'priority' => 10]);
        $this->postJson('/api/v1/cart/refresh')->assertJsonCount(1, 'data.promotions')->assertJsonPath('data.promotions.0.id', $expected->uuid);
    }

    #[DataProvider('couponFailures')]
    public function test_coupon_failure_codes(string $change, string $expected): void
    {
        [$cart] = $this->cart();
        $coupon = Coupon::factory()->create();
        match ($change) {
            'inactive' => $coupon->update(['is_active' => false]),
            'future' => $coupon->update(['starts_at' => now()->addDay()]),
            'expired' => $coupon->update(['ends_at' => now()->subDay()]),
            'limit' => $coupon->forceFill(['usage_limit' => 1, 'used_count' => 1])->save(),
            'auth' => $coupon->update(['usage_limit_per_user' => 1]),
            'promotion inactive' => $coupon->promotion->update(['is_active' => false]),
            'promotion future' => $coupon->promotion->update(['starts_at' => now()->addDay()]),
            'promotion expired' => $coupon->promotion->update(['ends_at' => now()->subDay()]),
            'promotion deleted' => $coupon->promotion->delete(),
            'promotion limit' => $coupon->promotion->forceFill(['usage_limit' => 1, 'used_count' => 1])->save(),
            'promotion auth' => $coupon->promotion->update(['usage_limit_per_user' => 1]),
            'branch' => $coupon->promotion->branches()->attach(Branch::factory()->create()),
            'minimum' => $coupon->promotion->update(['minimum_subtotal' => '100.01']),
            'targets' => $coupon->promotion->update(['application_type' => 'product']),
            default => null,
        };
        $this->postJson('/api/v1/cart/coupon', ['code' => $change === 'missing' ? 'MISSING' : $coupon->code])->assertUnprocessable()->assertJsonPath('errors.coupon.0.code', $expected);
        $this->assertNull($cart->fresh()->coupon_id);
        $this->assertDatabaseCount('promotion_usages', 0);
    }

    public static function couponFailures(): array
    {
        return [
            ['missing', 'COUPON_NOT_FOUND'], ['inactive', 'COUPON_INACTIVE'], ['future', 'COUPON_NOT_STARTED'], ['expired', 'COUPON_EXPIRED'],
            ['limit', 'COUPON_USAGE_LIMIT_REACHED'], ['auth', 'COUPON_AUTHENTICATION_REQUIRED'],
            ['promotion inactive', 'COUPON_INACTIVE'], ['promotion future', 'COUPON_NOT_STARTED'], ['promotion expired', 'COUPON_EXPIRED'],
            ['promotion deleted', 'COUPON_INACTIVE'], ['promotion limit', 'COUPON_USAGE_LIMIT_REACHED'], ['promotion auth', 'COUPON_AUTHENTICATION_REQUIRED'],
            ['branch', 'COUPON_NOT_AVAILABLE_FOR_BRANCH'], ['minimum', 'COUPON_MINIMUM_NOT_MET'], ['targets', 'COUPON_NOT_APPLICABLE'],
        ];
    }

    public function test_coupon_apply_remove_and_repricing_issues_do_not_consume_usage(): void
    {
        [$cart] = $this->cart();
        $coupon = Coupon::factory()->create();
        $this->postJson('/api/v1/cart/coupon', ['code' => '  '.strtolower($coupon->code).'  ', 'discount_total' => '99.99', 'coupon_id' => 999, 'final_total' => '0.01'])->assertOk()->assertJsonPath('data.coupon.code', $coupon->code)->assertJsonPath('data.summary.total', '80.00');
        $this->assertDatabaseCount('promotion_usages', 0);
        $this->assertSame(0, $coupon->fresh()->used_count);
        $coupon->update(['ends_at' => now()->subSecond()]);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.summary.discount_total', '0.00')->assertJsonPath('data.issues.0.code', 'COUPON_EXPIRED')->assertJsonPath('data.can_checkout', false);
        $this->postJson('/api/v1/cart/refresh')->assertJsonPath('data.coupon.code', $coupon->code)->assertJsonPath('data.issues.0.code', 'COUPON_EXPIRED');
        $this->postJson('/api/v1/checkout', $this->checkout())->assertUnprocessable()->assertJsonPath('errors.cart.0.code', 'COUPON_EXPIRED');
        $this->assertDatabaseCount('orders', 0);
        $this->deleteJson('/api/v1/cart/coupon')->assertOk()->assertJsonPath('data.coupon', null)->assertJsonPath('data.summary.total', '100.00')->assertJsonCount(0, 'data.issues');
    }

    public function test_cart_uses_only_its_own_branch_and_revalidates_category_changes(): void
    {
        [$cart,$assignment] = $this->cart();
        $promotion = Promotion::factory()->create(['application_type' => 'category']);
        $promotion->categories()->attach($assignment->product->category);
        $promotion->branches()->attach($assignment->branch);
        $this->postJson('/api/v1/cart/refresh', ['branch_uuid' => Branch::factory()->create()->uuid])->assertJsonPath('data.summary.discount_total', '20.00');
        $assignment->product->update(['category_id' => Category::factory()->create()->id]);
        $this->postJson('/api/v1/checkout', $this->checkout())->assertCreated()->assertJsonPath('data.pricing.discount_total', '0.00');
    }

    public function test_usage_limits_per_user_and_global_ledger_only_after_checkout(): void
    {
        $user = User::factory()->create();
        [$cart] = $this->cart(user: $user);
        $coupon = Coupon::factory()->create(['usage_limit_per_user' => 1, 'usage_limit' => 2]);
        $coupon->promotion->update(['usage_limit_per_user' => 1, 'usage_limit' => 2]);
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
        $this->assertDatabaseCount('promotion_usages', 0);
        $response = $this->postJson('/api/v1/checkout', $this->checkout())->assertCreated()->assertJsonPath('data.pricing.total', '80.00');
        $this->assertDatabaseHas('promotion_usages', ['promotion_id' => $coupon->promotion_id, 'coupon_id' => $coupon->id, 'user_id' => $user->id, 'discount_amount' => 20]);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, $coupon->promotion->fresh()->used_count);
        $this->postJson('/api/v1/checkout', $this->checkout())->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertDatabaseCount('promotion_usages', 1);
        $this->cart(user: $user);
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertUnprocessable()->assertJsonPath('errors.coupon.0.code', 'COUPON_USER_LIMIT_REACHED');
        $this->cart(user: User::factory()->create());
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkout())->assertCreated();
        $this->assertSame(2, $coupon->fresh()->used_count);
        $this->cart(user: User::factory()->create());
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertUnprocessable()->assertJsonPath('errors.coupon.0.code', 'COUPON_USAGE_LIMIT_REACHED');
    }

    public function test_promotion_per_user_ledger_limit_applies_independently_of_coupon(): void
    {
        $user = User::factory()->create();
        [$cart] = $this->cart(user: $user);
        $coupon = Coupon::factory()->create();
        $coupon->promotion->update(['usage_limit_per_user' => 1]);
        PromotionUsage::factory()->create(['promotion_id' => $coupon->promotion_id, 'coupon_id' => null, 'user_id' => $user->id]);
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertUnprocessable()->assertJsonPath('errors.coupon.0.code', 'COUPON_USER_LIMIT_REACHED');
    }

    public function test_two_previews_cannot_consume_last_coupon_usage_twice(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => 1]);
        [$a] = $this->cart();
        $tokenA = $this->defaultHeaders['X-Cart-Token'];
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
        [$b] = $this->cart();
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
        $this->withHeader('X-Cart-Token', $tokenA)->withHeader('X-Cart-UUID', $a->uuid)->postJson('/api/v1/checkout', $this->checkout())->assertCreated();
        $this->withHeader('X-Cart-UUID', $b->uuid);
        // Direct action uses a stale preview but must reload and recheck under locks.
        try {
            app(PlaceOrderAction::class)->handle($b, $this->checkout());
            $this->fail('The exhausted coupon must reject checkout.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
        }
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('promotion_usages', 1);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_automatic_limit_guest_policy_and_minimum_use_prediscount_subtotal(): void
    {
        [$cart] = $this->cart();
        $promotion = Promotion::factory()->create(['usage_limit' => 1, 'minimum_subtotal' => '100.00']);
        $this->postJson('/api/v1/checkout', $this->checkout())->assertCreated()->assertJsonPath('data.pricing.total', '80.00');
        $this->cart();
        $this->postJson('/api/v1/cart/refresh')->assertJsonPath('data.summary.discount_total', '0.00');
        $promotion->update(['usage_limit' => null, 'usage_limit_per_user' => 1]);
        $this->postJson('/api/v1/cart/refresh')->assertJsonPath('data.summary.discount_total', '0.00');
    }

    public function test_snapshots_and_cash_payment_use_trusted_totals_and_remain_immutable(): void
    {
        [$cart,$assignment] = $this->cart('60.00');
        $other = Product::factory()->create(['base_price' => '40.00']);
        $assignment->branch->products()->attach($other);
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $other->uuid, 'quantity' => 1])->assertCreated();
        $coupon = Coupon::factory()->create(['code' => 'SHISHLI10']);
        $coupon->promotion->update(['discount_type' => 'fixed', 'discount_value' => '10', 'name' => 'Original']);
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
        $response = $this->postJson('/api/v1/checkout', $this->checkout(['discount_total' => '99', 'discount_value' => '99', 'promotion_id' => 123, 'coupon_id' => 123, 'total' => '1', 'final_total' => '1']))->assertCreated()
            ->assertJsonPath('data.pricing.total', '90.00')->assertJsonPath('data.items.0.pricing.discount_total', '6.00')->assertJsonPath('data.items.1.pricing.discount_total', '4.00')
            ->assertJsonPath('data.promotions.0.name', 'Original')->assertJsonPath('data.promotions.0.coupon_code', 'SHISHLI10');
        $order = Order::where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame('90.00', $order->payments()->first()->amount);
        $original = $order->promotions->first()->getAttributes();
        $coupon->promotion->update(['name' => 'Changed', 'discount_value' => '99']);
        $coupon->update(['code' => 'CHANGED']);
        $coupon->delete();
        $assignment->product->update(['base_price' => '999']);
        $coupon->promotion->delete();
        $this->assertSame($original, $order->fresh()->promotions->first()->getAttributes());
        $this->withHeader('X-Order-Token', $response->json('data.access_token'))->getJson('/api/v1/orders/'.$order->uuid.'/guest')
            ->assertOk()->assertJsonPath('data.pricing.total', '90.00')->assertJsonPath('data.promotions.0.name', 'Original')->assertJsonPath('data.promotions.0.coupon_code', 'SHISHLI10');
    }

    public function test_usage_and_snapshots_roll_back_with_failed_transaction(): void
    {
        [$cart] = $this->cart();
        $coupon = Coupon::factory()->create();
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
        DB::beginTransaction();
        app(PlaceOrderAction::class)->handle($cart, $this->checkout());
        $this->assertDatabaseCount('promotion_usages', 1);
        DB::rollBack();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_promotions', 0);
        $this->assertDatabaseCount('promotion_usages', 0);
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(0, $coupon->promotion->fresh()->used_count);
    }

    #[DataProvider('deliveryThresholds')]
    public function test_free_delivery_uses_after_discount_but_minimum_uses_before(string $subtotal, string $discount, string $fee, string $total): void
    {
        [$cart,$assignment] = $this->cart($subtotal);
        DeliveryZone::factory()->for($assignment->branch)->create(['minimum_order' => '100', 'free_delivery_threshold' => '100', 'delivery_fee' => '5']);
        $coupon = Coupon::factory()->create();
        $coupon->promotion->update(['discount_type' => 'fixed', 'discount_value' => $discount]);
        $this->postJson('/api/v1/cart/coupon', ['code' => $coupon->code])->assertOk();
        $address = ['recipient_name' => 'Customer', 'phone' => '0501234567', 'city' => 'الرياض', 'district' => 'المصيف', 'street' => 'Main street'];
        $this->postJson('/api/v1/cart/delivery/quote', ['address' => array_intersect_key($address, array_flip(['city', 'district']))])->assertOk()->assertJsonPath('data.delivery_fee', $fee);
        $this->postJson('/api/v1/checkout', $this->checkout(['type' => 'delivery', 'address' => $address]))->assertCreated()->assertJsonPath('data.pricing.delivery_fee', $fee)->assertJsonPath('data.pricing.total', $total);
    }

    public static function deliveryThresholds(): array
    {
        return [['110', '20', '5.00', '95.00'], ['120', '10', '0.00', '110.00']];
    }

    public function test_public_promotions_exclude_codes_private_counters_and_invalid_schedules(): void
    {
        $branch = Branch::factory()->create();
        $global = Promotion::factory()->create();
        $targeted = Promotion::factory()->create();
        $targeted->branches()->attach($branch);
        $wrong = Promotion::factory()->create();
        $wrong->branches()->attach(Branch::factory()->create());
        Coupon::factory()->create(['code' => 'PRIVATECODE']);
        Promotion::factory()->inactive()->create();
        Promotion::factory()->expired()->create();
        Promotion::factory()->future()->create();
        $response = $this->getJson('/api/v1/promotions?branch_uuid='.$branch->uuid)->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('PRIVATECODE', $response->getContent());
        $response->assertJsonMissingPath('data.0.used_count')->assertJsonMissingPath('data.0.limits');
    }

    public function test_coupon_rate_limit_and_guest_cart_access(): void
    {
        $this->postJson('/api/v1/cart/coupon', ['code' => 'X'])->assertUnauthorized();
        [$cart] = $this->cart();
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/cart/coupon', ['code' => 'X'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/cart/coupon', ['code' => 'X'])->assertStatus(429);
    }
}
