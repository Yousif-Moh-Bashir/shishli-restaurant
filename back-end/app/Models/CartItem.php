<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'product_id', 'quantity', 'configuration_hash', 'base_price', 'options_total', 'unit_price', 'line_total'];

    protected $hidden = ['id', 'cart_id', 'product_id', 'configuration_hash'];

    public array $issues = [];

    public ?Collection $trustedOptions = null;

    public ?BranchProduct $assignment = null;

    protected static function booted(): void
    {
        static::creating(function (CartItem $item): void {
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

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function options(): HasMany
    {
        return $this->hasMany(CartItemOption::class)->orderBy('id');
    }
}
