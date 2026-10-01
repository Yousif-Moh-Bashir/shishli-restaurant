<?php
namespace App\Models;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
class PaymentTransaction extends Model
{
    use HasFactory;
    protected $fillable = ['payment_id', 'type', 'status', 'amount', 'provider_transaction_id',
        'provider_reference', 'request_reference', 'operation_key', 'failure_code', 'failure_message', 'metadata', 'processed_at'];
    protected $hidden = ['id', 'payment_id', 'request_reference', 'operation_key', 'metadata', 'failure_message'];
    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $transaction): void { $transaction->uuid ??= (string) Str::uuid(); });
        static::updating(function (PaymentTransaction $transaction): void {
            if ($transaction->isDirty(['payment_id', 'type', 'amount', 'operation_key'])
                || ($transaction->getOriginal('status') === PaymentTransactionStatus::Succeeded && $transaction->isDirty())) {
                throw new \LogicException('Successful transactions and financial snapshots are immutable.');
            }
        });
    }
    protected function casts(): array
    {
        return ['type' => PaymentTransactionType::class, 'status' => PaymentTransactionStatus::class,
            'amount' => 'decimal:2', 'processed_at' => 'datetime', 'metadata' => 'array'];
    }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
