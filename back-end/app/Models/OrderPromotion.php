<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromotionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderPromotion extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'promotion_uuid', 'promotion_name', 'promotion_type', 'discount_type', 'discount_value', 'coupon_code', 'discount_amount'];

    protected $hidden = ['id', 'order_id'];

    protected static function booted(): void
    {
        static::creating(function (OrderPromotion $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['discount_amount' => 'decimal:2', 'discount_value' => 'decimal:2', 'promotion_type' => PromotionType::class, 'discount_type' => DiscountType::class];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
