<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class OrderStatusHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'from_status', 'to_status', 'changed_by', 'source', 'note', 'metadata'];

    protected $hidden = ['id', 'order_id', 'changed_by', 'metadata'];

    protected static function booted(): void
    {
        static::creating(function (OrderStatusHistory $history): void {
            $history->uuid ??= (string) Str::uuid();
        });
        static::updating(function (): void {
            throw new LogicException('Order history is append-only.');
        });
        static::deleting(function (): void {
            throw new LogicException('Order history is append-only.');
        });
    }

    protected function casts(): array
    {
        return ['from_status' => OrderStatus::class, 'to_status' => OrderStatus::class, 'source' => OrderStatusSource::class, 'metadata' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
