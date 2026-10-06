<?php

namespace App\Models;

use App\Services\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    use HasFactory;

    protected $fillable = ['order_id', 'product_id', 'product_uuid', 'product_name', 'product_sku', 'category_uuid', 'category_name', 'quantity', 'base_price', 'options_total', 'unit_price', 'line_total', 'discount_total', 'line_total_after_discount'];

    protected $hidden = ['id', 'order_id', 'product_id'];

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            $item->uuid ??= (string) Str::uuid();
            $item->discount_total ??= '0.00';
            $item->line_total_after_discount ??= Money::decimal(Money::minor($item->line_total) - Money::minor($item->discount_total));
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'base_price' => 'decimal:2', 'options_total' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'discount_total' => 'decimal:2', 'line_total_after_discount' => 'decimal:2'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class)->orderBy('id');
    }
}
