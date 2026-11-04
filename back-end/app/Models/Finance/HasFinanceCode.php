<?php

namespace App\Models\Finance;

use Illuminate\Support\Str;

trait HasFinanceCode
{
    protected static function bootHasFinanceCode(): void
    {
        static::creating(function (FinanceModel $model): void {
            $model->code ??= 'custom_'.str_replace('-', '', (string) Str::uuid());
        });
        static::updating(function (FinanceModel $model): void {
            if ($model->isDirty('code')) {
                throw new \LogicException('Finance setting codes are immutable.');
            }
        });
    }
}
