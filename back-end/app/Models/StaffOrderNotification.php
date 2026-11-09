<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffOrderNotification extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'branch_id', 'activated_at', 'expires_at'];

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['activated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
