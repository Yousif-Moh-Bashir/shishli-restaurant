<?php

namespace App\Models;

use App\Enums\CartStatus;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Cart extends Model
{
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'branch_id', 'status', 'subtotal', 'total', 'expires_at'];

    protected $hidden = ['id', 'token', 'user_id', 'branch_id', 'active_user_id'];

    public array $issues = [];

    protected static function booted(): void
    {
        static::creating(function (Cart $cart): void {
            $cart->uuid ??= (string) Str::uuid();
            $cart->token ??= hash('sha256', Str::random(64));
        });
    }

    protected function casts(): array
    {
        return ['status' => CartStatus::class, 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'expires_at' => 'datetime'];
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

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
