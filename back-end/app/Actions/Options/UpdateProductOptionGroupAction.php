<?php

namespace App\Actions\Options;

use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\OptionConfiguration;
use Illuminate\Support\Facades\DB;

class UpdateProductOptionGroupAction
{
    public function __construct(private OptionConfiguration $configuration) {}

    /** @param array<string, mixed> $data */
    public function handle(Product $product, OptionGroup $group, array $data): OptionGroup
    {
        return DB::transaction(function () use ($product, $group, $data): OptionGroup {
            $group = OptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $product->optionGroups()->whereKey($group->id)->firstOrFail();
            $product->optionGroups()->updateExistingPivot($group->id, $data);
            $this->configuration->assertState($group);

            return $product->optionGroups()->whereKey($group->id)->firstOrFail()->load('values');
        }, 3);
    }
}
