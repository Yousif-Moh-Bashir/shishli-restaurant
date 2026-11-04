<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Outflow
{
    protected $table = 'finance_purchases';

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
