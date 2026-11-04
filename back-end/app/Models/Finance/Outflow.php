<?php

namespace App\Models\Finance;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class Outflow extends FinanceModel
{
    protected $fillable = ['date', 'amount', 'description', 'payment_method', 'invoice_number'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'version' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
