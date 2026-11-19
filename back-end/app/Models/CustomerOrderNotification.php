<?php

namespace App\Models;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerOrderNotification extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'order_id', 'order_status_history_id', 'type', 'status', 'title', 'body', 'occurred_at'];

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['type' => NotificationType::class, 'status' => OrderStatus::class,
            'occurred_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id)->whereNotNull('published_at')
            ->whereHas('order', fn (Builder $orders): Builder => $orders->where('user_id', $user->id));
    }
}
