<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPushDelivery extends Model
{
    use HasFactory;

    protected $fillable = ['notification_id', 'subscription_id', 'device_uuid', 'subscription_revision', 'activated_at', 'expires_at',
        'status', 'attempts', 'available_at', 'queued_at', 'sent_at', 'error_code'];

    protected function casts(): array
    {
        return ['subscription_revision' => 'integer', 'attempts' => 'integer', 'activated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'queued_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(CustomerOrderNotification::class, 'notification_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerPushSubscription::class, 'subscription_id');
    }
}
