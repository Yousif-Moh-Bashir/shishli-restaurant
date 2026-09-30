<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PricingService
{
    public function totalWithDelivery(string $subtotal, string $deliveryFee): string
    {
        return Money::decimal(Money::minor($subtotal) + Money::minor($deliveryFee));
    }

    /** @param Collection<int, OptionValue> $values Trusted selections from OptionSelectionValidator. */
    public function calculate(Product $product, Branch $branch, BranchProduct $assignment, Collection $values, int $quantity): ProductPriceResult
    {
        if ($quantity < 1 || $quantity > 50) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون بين 1 و50.']);
        }
        $base = Money::minor($this->getProductPriceForBranch($product, $branch, $assignment));
        $options = 0;
        foreach ($values as $value) {
            $options += Money::minor($value->price_modifier);
        }
        $unit = $base + $options;

        return new ProductPriceResult(Money::decimal($base), Money::decimal($options), Money::decimal($unit), Money::decimal($unit * $quantity));
    }

    public function getProductPriceForBranch(Product $product, Branch $branch, ?BranchProduct $assignment = null): string
    {
        $assignment ??= BranchProduct::where('branch_id', $branch->id)->where('product_id', $product->id)->firstOrFail();
        if ($assignment->branch_id !== $branch->id || $assignment->product_id !== $product->id) {
            throw new InvalidArgumentException('The assignment does not belong to this branch and product.');
        }

        return $assignment->price_override ?? $product->base_price;
    }
}
