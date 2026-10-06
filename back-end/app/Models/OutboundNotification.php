<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OutboundNotification extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'channel', 'status', 'recipient', 'recipient_name', 'subject', 'content', 'provider', 'provider_message_id', 'reference_type', 'reference_uuid', 'deduplication_key', 'attempts', 'max_attempts', 'last_error_code', 'last_error_message', 'sent_at', 'failed_at', 'metadata', 'processing_token', 'processing_started_at'];

    protected $hidden = ['id', 'processing_token'];

    protected static function booted(): void
    {
        static::creating(function (OutboundNotification $notification): void {
            $notification->uuid ??= (string) Str::uuid();
        });
        static::updating(function (OutboundNotification $notification): void {
            $original = $notification->getOriginal('metadata') ?? [];
            if (($original['template'] ?? null) !== ($notification->metadata['template'] ?? null)) {
                throw new \LogicException('Notification template snapshots are immutable.');
            }
            foreach (['recipient', 'recipient_name', 'subject', 'content', 'type', 'channel', 'reference_type', 'reference_uuid', 'deduplication_key'] as $field) {
                if ($notification->isDirty($field)) {
                    throw new \LogicException('Notification content and recipient snapshots are immutable.');
                }
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['type' => NotificationType::class, 'channel' => NotificationChannel::class, 'status' => NotificationStatus::class, 'attempts' => 'integer', 'max_attempts' => 'integer', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'processing_started_at' => 'datetime', 'metadata' => 'array'];
    }

    public function attemptLogs(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class, 'notification_id')->orderBy('attempt_number');
    }
}
