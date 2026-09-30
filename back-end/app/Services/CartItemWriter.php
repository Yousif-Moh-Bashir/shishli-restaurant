<?php

namespace App\Services;

use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CartItemWriter
{
    public function __construct(
        private ProductAvailabilityService $availability,
        private OptionSelectionValidator $options,
        private PricingService $pricing,
    ) {}

    public function product(Cart $cart, string $uuid): array
    {
        $product = Product::where('uuid', $uuid)->with(['category', 'optionGroups.values'])->first();
        $assignment = $product === null ? null : BranchProduct::where('branch_id', $cart->branch_id)->where('product_id', $product->id)->first();
        if ($product === null || ! $product->category?->is_active || ! $this->availability->isAvailable($product, $cart->branch, $assignment)) {
            throw ValidationException::withMessages(['product_uuid' => 'المنتج غير متوفر حاليًا في هذا الفرع.']);
        }

        return [$product, $assignment];
    }

    public function write(Cart $cart, Product $product, BranchProduct $assignment, array $selections, int $quantity, ?CartItem $source = null): void
    {
        $values = $this->options->validate($product, $selections);
        $hash = $this->options->signature($product, $values);
        $target = $cart->items()->where('configuration_hash', $hash)->first();
        if ($target !== null && $target->id !== $source?->id) {
            $quantity += $target->quantity;
        }
        $price = $this->pricing->calculate($product, $cart->branch, $assignment, $values, $quantity);
        if ($target === null && $source === null && $cart->items()->count() >= 100) {
            throw ValidationException::withMessages(['cart' => 'لا يمكن أن تتجاوز السلة 100 صنف مختلف.']);
        }
        $target ??= $source ?? new CartItem(['cart_id' => $cart->id, 'product_id' => $product->id]);
        $target->fill($price->attributes() + ['quantity' => $quantity, 'configuration_hash' => $hash])->save();
        if ($source !== null && $source->id !== $target->id) {
            $source->delete();
        }
        $target->options()->delete();
        $this->saveOptions($target, $values);
    }

    private function saveOptions(CartItem $item, Collection $values): void
    {
        $rows = $values->map(fn ($value): array => [
            'cart_item_id' => $item->id, 'option_group_id' => $value->option_group_id,
            'option_value_id' => $value->id, 'option_group_name' => $value->optionGroup->name,
            'option_value_name' => $value->name, 'price_modifier' => $value->price_modifier,
            'created_at' => now(), 'updated_at' => now(),
        ])->all();
        if ($rows !== []) {
            $item->options()->insert($rows);
        }
    }
}
