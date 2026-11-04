<?php

namespace App\Models\Finance;

class RevenueSource extends FinanceModel
{
    use HasFinanceCode;

    protected $table = 'finance_revenue_sources';

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
