<?php

namespace App\Models;

use App\Enums\StockReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockReservation extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'status' => StockReservationStatus::class,
            'reserved_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reservation): void {
            $reservation->uuid ??= (string) Str::uuid();
        });
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
