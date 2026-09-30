<?php

namespace App\Actions\Delivery;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

class DeleteDeliveryZoneAction
{
    public function handle(Branch $branch, string $uuid): void
    {
        DB::transaction(function () use ($branch, $uuid): void {
            $branch->deliveryZones()->where('uuid', $uuid)->lockForUpdate()->firstOrFail()->delete();
        }, 3);
    }
}
