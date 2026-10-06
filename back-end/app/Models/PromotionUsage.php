<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PromotionUsage extends Model
{
    use HasFactory;

    protected $fillable = ['promotion_id', 'coupon_id', 'order_id', 'user_id', 'discount_amount', 'used_at'];

    protected $hidden = ['id', 'promotion_id', 'coupon_id', 'order_id', 'user_id'];

    protected static function booted(): void
    {
        static::creating(function (PromotionUsage $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['discount_amount' => 'decimal:2', 'used_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
