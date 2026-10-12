<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderTiming;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Services\Notifications\PhoneNormalizer;
use App\Services\RestaurantSettingsService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    protected $attributes = ['order_timing' => 'asap'];

    protected $with = ['promotions'];

    public function promotions(): HasMany
    {
        return $this->hasMany(OrderPromotion::class)->orderBy('id');
    }

    use HasFactory;

    protected $fillable = ['user_id', 'branch_id', 'cart_id', 'order_number', 'type', 'status', 'payment_method', 'payment_status', 'customer_name', 'customer_phone', 'customer_email', 'subtotal', 'delivery_fee', 'discount_total', 'tax_total', 'total', 'currency', 'customer_notes', 'placed_at', 'order_timing', 'scheduled_for'];

    protected $hidden = ['id', 'user_id', 'branch_id', 'cart_id', 'driver_id', 'access_token', 'guest_access_token_hash', 'guest_access_token_expires_at', 'cancelled_by'];

    public ?string $issuedGuestAccessToken = null;

    /** Legacy assignment API hashes credentials immediately; the raw value exists only on the issuing in-memory instance. */
    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->issuedGuestAccessToken,
            set: function (#[\SensitiveParameter] ?string $token): array {
                $this->issuedGuestAccessToken = $token;

                return ['guest_access_token_hash' => $token === null ? null : hash('sha256', $token),
                    'guest_access_token_expires_at' => $token === null ? null : ($this->placed_at ?? now())->copy()
                        ->addDays(app(RestaurantSettingsService::class)->getInteger('orders.guest_tracking_token_days'))];
            },
        );
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->uuid ??= (string) Str::uuid();
            $order->customer_phone_normalized = app(PhoneNormalizer::class)->normalize($order->customer_phone, config('outbound_notifications.phone_country'));
        });
    }

    protected function casts(): array
    {
        return ['delivery_status' => DeliveryStatus::class, 'driver_accepted_at' => 'immutable_datetime',
            'delivery_picked_up_at' => 'immutable_datetime', 'delivery_on_the_way_at' => 'immutable_datetime',
            'delivery_delivered_at' => 'immutable_datetime', 'delivery_failed_at' => 'immutable_datetime',
            'order_timing' => OrderTiming::class, 'scheduled_for' => 'immutable_datetime', 'type' => OrderType::class, 'status' => OrderStatus::class, 'payment_method' => PaymentMethod::class, 'payment_status' => PaymentStatus::class,
            'guest_access_token_expires_at' => 'immutable_datetime', 'estimated_ready_at' => 'immutable_datetime',
            'estimated_delivery_from' => 'immutable_datetime', 'estimated_delivery_to' => 'immutable_datetime',
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

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function deliveryHistory(): HasMany
    {
        return $this->hasMany(OrderDeliveryHistory::class)->orderBy('id');
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
