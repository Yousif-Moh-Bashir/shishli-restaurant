<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Outflow
{
    protected $table = 'finance_expenses';

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
}
