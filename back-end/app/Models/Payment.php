<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'method', 'provider', 'status', 'amount', 'currency', 'idempotency_key'];

    protected $hidden = ['id', 'order_id', 'idempotency_key', 'metadata', 'provider_payment_id', 'provider_reference', 'failure_message', 'checkout_url'];

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
        static::updating(function (Payment $payment): void {
            if ($payment->isDirty(['order_id', 'amount', 'currency', 'method', 'provider', 'idempotency_key'])) {
                throw new \LogicException('Payment financial snapshots are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'status' => PaymentStatus::class, 'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2', 'metadata' => 'array', 'paid_at' => 'datetime',
            'failed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class)->orderBy('id');
    }
}
