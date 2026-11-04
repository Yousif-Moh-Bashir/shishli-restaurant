<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class FinanceModel extends Model
{
    protected $hidden = ['id', 'branch_id', 'created_by', 'approved_by', 'attachment_path', 'category_id', 'supplier_id'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
