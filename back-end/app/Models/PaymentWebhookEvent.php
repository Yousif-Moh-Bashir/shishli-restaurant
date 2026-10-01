<?php
namespace App\Models;
use App\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
class PaymentWebhookEvent extends Model
{
    use HasFactory;
    public const UPDATED_AT = null;
    protected $fillable = ['provider', 'provider_event_id', 'fingerprint', 'event_type', 'signature_valid', 'status', 'payment_id', 'payload', 'processed_at', 'failure_message'];
    protected $hidden = ['id', 'payment_id', 'payload', 'fingerprint', 'failure_message'];
    protected static function booted(): void
    {
        static::creating(function (PaymentWebhookEvent $event): void { $event->uuid ??= (string) Str::uuid(); });
    }
    protected function casts(): array
    {
        return ['status' => WebhookEventStatus::class, 'signature_valid' => 'boolean', 'payload' => 'array', 'processed_at' => 'datetime'];
    }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
