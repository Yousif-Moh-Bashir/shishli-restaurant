<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['type' => StockMovementType::class, 'quantity' => 'decimal:3', 'on_hand_before' => 'decimal:3',
            'on_hand_after' => 'decimal:3', 'reserved_before' => 'decimal:3', 'reserved_after' => 'decimal:3', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $movement->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new \LogicException('Stock movements are immutable.'));
        static::deleting(fn () => throw new \LogicException('Stock movements are immutable.'));
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
