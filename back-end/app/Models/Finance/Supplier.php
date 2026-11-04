<?php

namespace App\Models\Finance;

class Supplier extends FinanceModel
{
    protected $table = 'finance_suppliers';

    protected $fillable = ['name', 'phone', 'tax_number', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
