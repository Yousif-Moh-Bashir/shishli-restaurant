<?php

namespace App\Services;

use App\Enums\PromotionIssueCode;
use App\Enums\PromotionType;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use LogicException;

class PromotionEngine
{
    public function __construct(private PromotionEligibilityService $eligibility, private DiscountCalculator $calculator) {}

    public function calculate(Cart $cart, bool $lock = false): CartDiscountResult
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('Checkout promotion locks require a transaction.');
        }
        $cart->loadMissing(['items.product', 'coupon']);
        $subtotal = $cart->items->sum(fn ($item): int => Money::minor($item->line_total));
        $remaining = $cart->items->mapWithKeys(fn ($item): array => [$item->uuid => Money::minor($item->line_total)])->all();
        $allocated = array_fill_keys(array_keys($remaining), 0);
        $coupon = $cart->coupon;
        $query = Promotion::query()->where(function (Builder $q) use ($cart, $coupon): void {
            $q->where(fn (Builder $automatic): Builder => $automatic->where('type', PromotionType::Automatic->value)->currentlyValid()->forBranch($cart->branch_id));
            if ($coupon !== null) {
                $q->orWhere('id', $coupon->promotion_id);
            }
        })->with($lock ? [
            'branches' => fn (BelongsToMany $relation): BelongsToMany => $relation->lockForUpdate(),
            'products' => fn (BelongsToMany $relation): BelongsToMany => $relation->lockForUpdate(),
            'categories' => fn (BelongsToMany $relation): BelongsToMany => $relation->lockForUpdate(),
        ] : ['branches', 'products', 'categories']);
        if ($cart->user_id !== null) {
            $query->withCount(['usages as user_usage_count' => fn (Builder $q): Builder => $q->where('user_id', $cart->user_id)]);
        }
        // Lock all candidates in primary-key order before priority sorting to prevent lock-order inversion.
        if ($lock) {
            $query->lockForUpdate();
        }
        $promotions = $query->orderBy('id')->get();
        if ($coupon !== null && $lock) {
            $coupon = Coupon::withTrashed()->whereKey($coupon->id)->lockForUpdate()->first();
            $cart->setRelation('coupon', $coupon);
        }
        if ($coupon !== null && $cart->user_id !== null) {
            $coupon->loadCount(['usages as user_usage_count' => fn (Builder $q): Builder => $q->where('user_id', $cart->user_id)]);
        }
        $promotions = $promotions->sort(fn (Promotion $a, Promotion $b): int => ($b->priority <=> $a->priority) ?: strcmp($a->uuid, $b->uuid));
        $results = [];
        $rejected = $cart->coupon_id !== null ? PromotionIssueCode::NotApplicable : null;
        if ($coupon !== null && $coupon->promotion->trashed()) {
            $rejected = PromotionIssueCode::Inactive;
        }
        $appliedNonStackable = false;
        foreach ($promotions as $promotion) {
            $selected = $coupon !== null && $coupon->promotion_id === $promotion->id ? $coupon : null;
            if ($promotion->type === PromotionType::Coupon && $selected === null) {
                continue;
            }
            if ($selected !== null && $promotion->type !== PromotionType::Coupon) {
                $rejected = PromotionIssueCode::NotApplicable;

                continue;
            }
            $issue = $this->eligibility->evaluate($promotion, $cart, $subtotal, $selected);
            if ($issue !== null) {
                if ($selected !== null) {
                    $rejected = $issue;
                }

                continue;
            }
            if ($appliedNonStackable || ($results !== [] && ! $promotion->is_stackable)) {
                continue;
            }
            $discount = $this->calculator->calculate($promotion, $cart, $remaining);
            if ($discount->discountAmount === 0) {
                continue;
            }
            $results[] = new PromotionResult($promotion, $selected, $discount);
            foreach ($discount->affectedItems as $uuid => $amount) {
                $remaining[$uuid] -= $amount;
                $allocated[$uuid] += $amount;
            }
            $appliedNonStackable = ! $promotion->is_stackable;
            if ($selected !== null) {
                $rejected = null;
            }
        }
        $total = array_sum($allocated);

        return new CartDiscountResult($subtotal, $total, $subtotal - $total, $results, $allocated, $rejected);
    }

    public function present(Cart $cart, CartDiscountResult $result): void
    {
        $cart->issues = array_values(array_filter($cart->issues, fn (array $issue): bool => ! str_starts_with($issue['code'], 'COUPON_')));
        $cart->discountResult = $result;
        $cart->subtotal = Money::decimal($result->subtotalBeforeDiscount);
        $cart->discount_total = Money::decimal($result->discountTotal);
        $cart->total = Money::decimal($result->subtotalAfterDiscount);
        foreach ($cart->items as $item) {
            $item->discountTotal = Money::decimal($result->itemDiscounts[$item->uuid] ?? 0);
            $item->lineTotalAfterDiscount = Money::decimal(Money::minor($item->line_total) - Money::minor($item->discountTotal));
        }
        if ($result->rejectedCoupon !== null) {
            $cart->issues[] = $result->rejectedCoupon->issue();
        }
    }
}
