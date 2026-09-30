<?php

namespace App\Actions\Options;

use App\Models\OptionGroup;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DetachProductOptionGroupAction
{
    public function handle(Product $product, OptionGroup $group): void
    {
        DB::transaction(function () use ($product, $group): void {
            $group = OptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $product->optionGroups()->whereKey($group->id)->firstOrFail();
            $product->optionGroups()->detach($group->id);
        }, 3);
    }
}
