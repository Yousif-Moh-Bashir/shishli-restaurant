<?php

namespace Tests\Feature;

use App\Actions\Orders\PlaceOrderAction;
use App\Actions\Promotions\SaveCouponAction;
use App\Actions\Promotions\UpdatePromotionAction;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use App\Services\CartStateService;
use App\Services\Money;
use App\Services\PromotionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromotionRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private function cart(?User $user = null): Cart
    {
        $assignment = BranchProduct::factory()->create(['price_override' => null]);
        $assignment->product->update(['base_price' => '100.00']);
        $cart = Cart::factory()->create(['user_id' => $user?->id, 'branch_id' => $assignment->branch_id, 'subtotal' => '100.00', 'total' => '100.00']);
        CartItem::factory()->for($cart)->for($assignment->product)->create(['quantity' => 1, 'base_price' => '100.00', 'options_total' => '0.00', 'unit_price' => '100.00', 'line_total' => '100.00']);

        return $cart;
    }

    private function payload(): array
    {
        return ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'Customer', 'phone' => '0501234567']];
    }

    #[DataProvider('staleCouponChanges')]
    public function test_checkout_rejects_coupon_invalidated_after_preview(string $change, string $code): void
    {
        $cart = $this->cart();
        $coupon = Coupon::factory()->create();
        $cart->update(['coupon_id' => $coupon->id]);
        $preview = app(CartStateService::class)->load($cart);
        $this->assertSame('80.00', $preview->total);
        match ($change) {
            'coupon expired' => $coupon->update(['ends_at' => now()->subSecond()]),
            'promotion expired' => $coupon->promotion->update(['ends_at' => now()->subSecond()]),
            'coupon deleted' => $coupon->delete(),
            'promotion deleted' => $coupon->promotion->delete(),
            'coupon exhausted' => $coupon->forceFill(['usage_limit' => 1, 'used_count' => 1])->save(),
            'promotion exhausted' => $coupon->promotion->forceFill(['usage_limit' => 1, 'used_count' => 1])->save(),
            'product price' => $cart->items->first()->product->update(['base_price' => '5.00']),
        };
        if ($change === 'product price') {
            $coupon->promotion->update(['minimum_subtotal' => '100']);
        }
        try {
            app(PlaceOrderAction::class)->handle($preview, $this->payload());
            $this->fail('Invalid coupons cannot checkout.');
        } catch (HttpResponseException $exception) {
            $data = json_decode($exception->getResponse()->getContent(), true);
            $this->assertSame($code, $data['errors']['cart'][0]['code']);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('promotion_usages', 0);
        $this->assertDatabaseCount('order_promotions', 0);
    }

    public static function staleCouponChanges(): array
    {
        return [['coupon expired', 'COUPON_EXPIRED'], ['promotion expired', 'COUPON_EXPIRED'], ['coupon deleted', 'COUPON_INACTIVE'], ['promotion deleted', 'COUPON_INACTIVE'], ['coupon exhausted', 'COUPON_USAGE_LIMIT_REACHED'], ['promotion exhausted', 'COUPON_USAGE_LIMIT_REACHED'], ['product price', 'COUPON_MINIMUM_NOT_MET']];
    }

    public function test_automatic_expiration_is_recalculated_at_checkout(): void
    {
        $cart = $this->cart();
        $promotion = Promotion::factory()->create();
        $this->assertSame('80.00', app(CartStateService::class)->load($cart)->total);
        $promotion->update(['ends_at' => now()->subSecond()]);
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload());
        $this->assertSame('100.00', $order->total);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertDatabaseCount('promotion_usages', 0);
    }

    public function test_stale_admin_patch_uses_locked_effective_discount_type_and_rolls_back(): void
    {
        $promotion = Promotion::factory()->fixed()->create();
        $stale = $promotion->replicate();
        $stale->id = $promotion->id;
        $promotion->update(['discount_type' => 'percentage']);
        try {
            app(UpdatePromotionAction::class)->handle($stale, ['discount_value' => '101']);
            $this->fail('Effective percentage must be validated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discount_value', $exception->errors());
        }
        $this->assertSame('10.00', $promotion->fresh()->discount_value);
    }

    public function test_stale_admin_coupon_schedule_patch_is_validated_under_lock(): void
    {
        $coupon = Coupon::factory()->create(['starts_at' => '2030-01-01', 'ends_at' => '2030-03-01']);
        $stale = $coupon->fresh();
        $coupon->update(['ends_at' => '2030-02-01']);
        try {
            app(SaveCouponAction::class)->handle($coupon->promotion, ['starts_at' => '2030-02-15'], $stale);
            $this->fail('Effective dates must be validated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_at', $exception->errors());
        }
        $this->assertSame('2030-01-01', $coupon->fresh()->starts_at->toDateString());
    }

    public function test_schedule_boundary_is_inclusive_and_minimum_checked_before_stacking(): void
    {
        $this->freezeTime();
        $cart = $this->cart();
        Promotion::factory()->fixed()->stackable()->create(['starts_at' => now(), 'ends_at' => now()->addSecond(), 'minimum_subtotal' => '100', 'discount_value' => '20', 'priority' => 10]);
        Promotion::factory()->fixed()->stackable()->create(['starts_at' => now()->subSecond(), 'ends_at' => now(), 'minimum_subtotal' => '100', 'discount_value' => '10']);
        $result = app(PromotionEngine::class)->calculate(app(CartStateService::class)->load($cart));
        $this->assertSame(3000, $result->discountTotal);
        $this->assertCount(2, $result->appliedPromotions);
    }

    public function test_deleted_branch_target_does_not_turn_offer_into_all_branch_offer(): void
    {
        $cart = $this->cart();
        $other = Branch::factory()->create();
        $promotion = Promotion::factory()->create();
        $promotion->branches()->attach($other);
        $other->delete();
        $this->assertSame('0.00', app(CartStateService::class)->load($cart)->discount_total);
    }

    public function test_per_user_automatic_limit_uses_ledger_and_excludes_guest(): void
    {
        $user = User::factory()->create();
        $cart = $this->cart($user);
        $promotion = Promotion::factory()->create(['usage_limit_per_user' => 1]);
        PromotionUsage::factory()->create(['promotion_id' => $promotion->id, 'user_id' => $user->id]);
        $this->assertSame('0.00', app(CartStateService::class)->load($cart)->discount_total);
        $cart2 = $this->cart(User::factory()->create());
        $this->assertSame('20.00', app(CartStateService::class)->load($cart2)->discount_total);
        $this->assertSame('0.00', app(CartStateService::class)->load($this->cart())->discount_total);
    }

    public function test_last_automatic_usage_is_not_reused_by_a_second_stale_preview(): void
    {
        $promotion = Promotion::factory()->create(['usage_limit' => 1]);
        $a = app(CartStateService::class)->load($this->cart());
        $b = app(CartStateService::class)->load($this->cart());
        $this->assertSame('20.00', $a->discount_total);
        $this->assertSame('20.00', $b->discount_total);
        $first = app(PlaceOrderAction::class)->handle($a, $this->payload());
        $second = app(PlaceOrderAction::class)->handle($b, $this->payload());
        $this->assertSame('20.00', $first->discount_total);
        $this->assertSame('0.00', $second->discount_total);
        $this->assertDatabaseCount('promotion_usages', 1);
        $this->assertSame(1, $promotion->fresh()->used_count);
    }

    public function test_promotion_queries_are_bounded_as_candidate_and_item_counts_grow(): void
    {
        $cart = $this->cart(User::factory()->create());
        app(CartStateService::class)->load($cart);
        Promotion::factory()->stackable()->create();
        DB::enableQueryLog();
        app(PromotionEngine::class)->calculate($cart);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        Promotion::factory()->stackable()->count(10)->create(['usage_limit_per_user' => 10]);
        for ($i = 0; $i < 5; $i++) {
            $product = Product::factory()->create(['base_price' => '1.00']);
            $cart->branch->products()->attach($product);
            CartItem::factory()->for($cart)->for($product)->create(['quantity' => 3, 'base_price' => '1.00', 'options_total' => '0.00', 'unit_price' => '1.00', 'line_total' => '3.00']);
        }
        app(CartStateService::class)->load($cart);
        DB::enableQueryLog();
        $result = app(PromotionEngine::class)->calculate($cart);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertLessThanOrEqual($small + 1, $large);
        $this->assertLessThanOrEqual(11500, $result->discountTotal);
        $this->assertSame($result->discountTotal, array_sum($result->itemDiscounts));
    }

    public function test_maximum_zero_no_discount_and_no_usage(): void
    {
        $cart = $this->cart();
        Promotion::factory()->create(['maximum_discount' => '0.00']);
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload());
        $this->assertSame('0.00', $order->discount_total);
        $this->assertDatabaseCount('promotion_usages', 0);
    }

    public function test_order_allocation_with_quantities_and_rounding_matches_order_discount(): void
    {
        $cart = $this->cart();
        $cart->items()->delete();
        foreach (['0.01', '0.02', '0.03'] as $price) {
            $product = Product::factory()->create(['base_price' => $price]);
            $cart->branch->products()->attach($product);
            CartItem::factory()->for($cart)->for($product)->create(['quantity' => 3, 'base_price' => $price, 'options_total' => '0.00', 'unit_price' => $price, 'line_total' => Money::decimal(Money::minor($price) * 3)]);
        }
        Promotion::factory()->fixed()->create(['discount_value' => '0.05']);
        $order = app(PlaceOrderAction::class)->handle($cart, $this->payload());
        $this->assertSame('0.05', $order->discount_total);
        $this->assertSame('0.13', $order->total);
        $this->assertSame(5, $order->items->sum(fn ($item): int => Money::minor($item->discount_total)));
        $this->assertSame(13, $order->items->sum(fn ($item): int => Money::minor($item->line_total_after_discount)));
    }
}
