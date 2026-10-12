<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Notifications\PhoneNormalizer;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['id', 'password', 'remember_token', 'driver_branch_id'])]
class User extends Authenticatable
{
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (! $user->exists || $user->isDirty('phone') || $user->phone_normalized === null) {
                $user->phone_normalized = $user->phone === null ? null : app(PhoneNormalizer::class)->normalize($user->phone, config('outbound_notifications.phone_country'));
                if ($user->exists && $user->isDirty('phone')) {
                    $user->phone_verified_at = null;
                }
            }
        });
    }

    public function defaultAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default', true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function driverBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'driver_branch_id')->withTrashed();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Order::class, 'driver_id');
    }

    public function driverCollections(): HasMany
    {
        return $this->hasMany(DriverCashCollection::class, 'driver_id');
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    protected string $guard_name = 'web';

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'driver_enabled' => 'boolean',
            'driver_available' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
