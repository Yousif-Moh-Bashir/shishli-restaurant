<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'product_id', 'product_uuid', 'product_name', 'product_sku', 'quantity', 'base_price', 'options_total', 'unit_price', 'line_total'];

    protected $hidden = ['id', 'order_id', 'product_id'];

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'base_price' => 'decimal:2', 'options_total' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
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
