<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryProgressAlert extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'driver_id', 'stage', 'progress_key', 'last_progress_at', 'reminded_at', 'escalated_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['last_progress_at' => 'immutable_datetime', 'reminded_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
