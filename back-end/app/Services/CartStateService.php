<?php

namespace App\Services;

use App\Enums\CartIssueCode;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Validation\ValidationException;

class CartStateService
{
    public function __construct(private OptionSelectionValidator $options, private ProductAvailabilityService $availability, private PricingService $pricing, private PromotionEngine $promotions, private InventoryAvailabilityService $inventory) {}

    public function acceptsOrders(Branch $branch): bool
    {
        return ! $branch->trashed() && $branch->is_active && $branch->accepts_orders
            && app(RestaurantSettingsService::class)->getBoolean('orders.enabled');
    }

    public function assertAcceptsOrders(Branch $branch): void
    {
        if (! $this->acceptsOrders($branch)) {
            throw ValidationException::withMessages(['branch' => CartIssueCode::BranchNotAcceptingOrders->issue()['message']]);
        }
    }

    public function load(Cart $cart): Cart
    {
        $cart->load([
            'branch', 'items.product.category', 'items.product.primaryImage',
            'items.product.optionGroups.values', 'items.options.optionGroup', 'items.options.optionValue',
        ]);
        $assignments = BranchProduct::where('branch_id', $cart->branch_id)
            ->whereIn('product_id', $cart->items->pluck('product_id'))->get()->keyBy('product_id');
        $cart->issues = $this->acceptsOrders($cart->branch) ? [] : [CartIssueCode::BranchNotAcceptingOrders->issue()];
        $currentSubtotal = 0;
        $this->inventory->load($cart->branch, $cart->items->pluck('product')->unique('id')->values());
        $quantities = $cart->items->groupBy('product_id')->map(fn ($items) => $items->sum('quantity'));
        foreach ($cart->items as $item) {
            $item->issues = [];
            $item->trustedOptions = null;
            $item->assignment = $assignments->get($item->product_id);
            if ($item->assignment === null) {
                $item->issues[] = CartIssueCode::ProductNotInBranch->issue();
            }
            $product = $item->product;
            if (! $this->availability->isOperationallyAvailable($product, $cart->branch, $item->assignment)
                || ! $product->category?->is_active) {
                if ($item->assignment !== null || $product->trashed() || ! $product->is_active || ! $product->is_available || ! $product->category?->is_active) {
                    $item->issues[] = CartIssueCode::ProductUnavailable->issue();
                }
            }
            $stockIssue = $this->inventory->checkProductAvailability($cart->branch, $product, (int) $quantities[$item->product_id]);
            if ($stockIssue !== null) {
                $item->issues[] = ['code' => $stockIssue->value, 'message' => $stockIssue->message(), 'product_uuid' => $product->uuid];
            }
            try {
                $item->trustedOptions = $this->options->validate($product, $this->selections($item));
            } catch (OptionSelectionException $exception) {
                $item->issues[] = $exception->issueCode->issue($exception->getMessage());
            }
            if ($item->issues === []) {
                try {
                    $price = $this->pricing->calculate($product, $cart->branch, $item->assignment, $item->trustedOptions, $item->quantity);
                    $currentSubtotal += Money::minor($price->lineTotal);
                } catch (ValidationException) {
                    $item->issues[] = CartIssueCode::PriceLimitExceeded->issue();
                }
            }
            if ($item->issues !== []) {
                $currentSubtotal += Money::minor($item->line_total);
            }
        }
        try {
            Money::decimal($currentSubtotal);
        } catch (ValidationException) {
            $cart->issues[] = CartIssueCode::PriceLimitExceeded->issue();
        }

        $this->promotions->present($cart, $this->promotions->calculate($cart));

        return $cart;
    }

    public function selections(CartItem $item): array
    {
        return $item->options->groupBy('option_group_id')->map(fn ($options): array => [
            'option_group_uuid' => $options->first()->optionGroup->uuid,
            'option_value_uuids' => $options->map(fn ($option): string => $option->optionValue->uuid)->all(),
        ])->values()->all();
    }
}
