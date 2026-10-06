<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotificationAttempt extends Model
{
    use HasFactory;

    protected $fillable = ['notification_id', 'attempt_number', 'status', 'provider', 'provider_message_id', 'error_code', 'error_message', 'started_at', 'finished_at', 'metadata'];

    protected $hidden = ['id', 'notification_id'];

    protected static function booted(): void
    {
        static::creating(function (NotificationAttempt $attempt): void {
            $attempt->uuid ??= (string) Str::uuid();
        });
        static::updating(function (NotificationAttempt $attempt): void {
            if ($attempt->getRawOriginal('finished_at') !== null) {
                throw new \LogicException('Completed attempt history is immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \LogicException('Attempt history cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['status' => NotificationStatus::class, 'attempt_number' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'metadata' => 'array'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(OutboundNotification::class, 'notification_id');
    }
}
