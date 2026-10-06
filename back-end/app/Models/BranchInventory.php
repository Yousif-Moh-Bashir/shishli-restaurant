<?php

namespace App\Models;

use App\Services\StockQuantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BranchInventory extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'product_id', 'low_stock_threshold', 'is_tracking'];

    protected $attributes = ['on_hand' => '0.000', 'reserved' => '0.000', 'is_tracking' => true];

    protected function casts(): array
    {
        return ['on_hand' => 'decimal:3', 'reserved' => 'decimal:3', 'low_stock_threshold' => 'decimal:3', 'is_tracking' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $inventory): void {
            $inventory->uuid ??= (string) Str::uuid();
        });
        static::saving(function (self $inventory): void {
            $onHand = StockQuantity::minor($inventory->on_hand);
            $reserved = StockQuantity::minor($inventory->reserved);
            if ($reserved > $onHand) {
                throw new \LogicException('Reserved stock cannot exceed physical stock.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Inventory history must be preserved; disable tracking instead.'));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function availableMinor(): int
    {
        return StockQuantity::minor($this->on_hand) - StockQuantity::minor($this->reserved);
    }

    public function available(): string
    {
        return StockQuantity::decimal($this->availableMinor());
    }

    public function isLowStock(): bool
    {
        return $this->is_tracking && $this->low_stock_threshold !== null && $this->availableMinor() <= StockQuantity::minor($this->low_stock_threshold);
    }

    public function isOutOfStock(): bool
    {
        return $this->is_tracking && $this->availableMinor() <= 0;
    }
}
