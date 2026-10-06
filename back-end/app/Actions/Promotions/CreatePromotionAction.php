<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePromotionAction
{
    public function __construct(private UpdatePromotionAction $update) {}

    public function handle(array $data): Promotion
    {
        return DB::transaction(function () use ($data): Promotion {
            $data['slug'] ??= (Str::slug($data['name']) ?: 'promotion').'-'.Str::lower(Str::random(12));
            $promotion = Promotion::create(Arr::except($data, ['branches', 'products', 'categories']));

            return $this->update->handle($promotion, $data);
        }, 3);
    }
}
