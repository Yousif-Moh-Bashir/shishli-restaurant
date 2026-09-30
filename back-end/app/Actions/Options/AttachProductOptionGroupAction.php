<?php

namespace App\Actions\Options;

use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\OptionConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachProductOptionGroupAction
{
    public function __construct(private OptionConfiguration $configuration) {}

    /** @param array<string, mixed> $data */
    public function handle(Product $product, array $data): OptionGroup
    {
        return DB::transaction(function () use ($product, $data): OptionGroup {
            $group = OptionGroup::where('uuid', $data['option_group_uuid'])->lockForUpdate()->first();
            if ($group === null) {
                throw ValidationException::withMessages(['option_group_uuid' => 'مجموعة الخيارات المحددة غير موجودة.']);
            }
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->optionGroups()->whereKey($group->id)->exists()) {
                throw ValidationException::withMessages(['option_group_uuid' => 'مجموعة الخيارات مرتبطة بهذا المنتج مسبقًا.']);
            }
            unset($data['option_group_uuid']);
            $this->configuration->assertState($group, $data);
            $product->optionGroups()->attach($group, $data);

            return $product->optionGroups()->whereKey($group->id)->firstOrFail()->load('values');
        }, 3);
    }
}
