<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use Illuminate\Support\Facades\DB;

class DeletePromotionAction
{
    public function handle(Promotion $promotion): void
    {
        DB::transaction(function () use ($promotion): void {
            Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail()->delete();
        }, 3);
    }
}
