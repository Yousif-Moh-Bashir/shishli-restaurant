<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DriverCashCollection extends Model
{
    protected $fillable = ['driver_id', 'order_id', 'payment_id', 'amount', 'currency', 'collected_at', 'settlement_id', 'settled_at'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'collected_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DriverCashSettlement::class, 'settlement_id');
    }
}
