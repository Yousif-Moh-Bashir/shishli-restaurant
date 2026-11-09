<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffNotificationDelivery extends Model
{
    protected $fillable = ['notification_id', 'channel', 'destination', 'status', 'attempts', 'queued_at', 'available_at', 'sent_at', 'error_code'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'queued_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(StaffOrderNotification::class, 'notification_id');
    }
}
