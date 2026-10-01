<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'branch_id', 'cart_id', 'order_number', 'type', 'status', 'payment_method', 'payment_status', 'customer_name', 'customer_phone', 'customer_email', 'subtotal', 'delivery_fee', 'discount_total', 'tax_total', 'total', 'currency', 'customer_notes', 'placed_at'];

    protected $hidden = ['id', 'user_id', 'branch_id', 'cart_id', 'access_token', 'cancelled_by'];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['type' => OrderType::class, 'status' => OrderStatus::class, 'payment_method' => PaymentMethod::class, 'payment_status' => PaymentStatus::class,
            'subtotal' => 'decimal:2', 'delivery_fee' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'placed_at' => 'datetime',
            'confirmed_at' => 'datetime', 'preparing_at' => 'datetime', 'ready_at' => 'datetime',
            'out_for_delivery_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
