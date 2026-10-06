<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionApplicationType;
use App\Enums\PromotionType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\PromotionConfigurationValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdatePromotionAction
{
    public function __construct(private PromotionConfigurationValidator $configuration) {}

    public function handle(Promotion $promotion, array $data): Promotion
    {
        return DB::transaction(function () use ($promotion, $data): Promotion {
            $promotion = Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $promotion->fill(Arr::except($data, ['branches', 'products', 'categories']));
            if ($promotion->type === PromotionType::Automatic && $promotion->coupons()->exists()) {
                throw ValidationException::withMessages(['type' => 'احذف الكوبونات أولًا.']);
            }
            if (! filled($promotion->slug)) {
                $promotion->slug = (Str::slug($promotion->name) ?: 'promotion').'-'.Str::lower(Str::random(12));
            }
            $promotion->save();
            foreach (['branches' => Branch::class, 'products' => Product::class, 'categories' => Category::class] as $relation => $model) {
                $relevant = $relation === 'branches' || ($relation === 'products' && $promotion->application_type === PromotionApplicationType::Product) || ($relation === 'categories' && $promotion->application_type === PromotionApplicationType::Category);
                if (! $relevant) {
                    $promotion->$relation()->sync([]);
                } elseif (array_key_exists($relation, $data)) {
                    $promotion->$relation()->sync($model::whereIn('uuid', $data[$relation])->pluck('id'));
                }
            }
            $this->configuration->validate($promotion);

            return $promotion->load(['branches', 'products', 'categories']);
        }, 3);
    }
}
