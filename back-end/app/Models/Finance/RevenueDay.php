<?php

namespace App\Models\Finance;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueDay extends FinanceModel
{
    protected $table = 'finance_revenue_days';

    protected $fillable = ['date', 'notes'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'total' => 'decimal:2', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RevenueLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
