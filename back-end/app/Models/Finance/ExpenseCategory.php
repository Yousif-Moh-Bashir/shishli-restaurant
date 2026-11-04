<?php

namespace App\Models\Finance;

class ExpenseCategory extends FinanceModel
{
    use HasFinanceCode;

    protected $table = 'finance_expense_categories';

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
