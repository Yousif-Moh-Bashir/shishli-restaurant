<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromotionApplicationType;
use App\Enums\PromotionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Promotion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'type', 'application_type', 'discount_type', 'discount_value', 'maximum_discount', 'minimum_subtotal', 'starts_at', 'ends_at', 'is_active', 'priority', 'is_stackable', 'usage_limit', 'usage_limit_per_user', 'first_order_only', 'is_registration_offer'];

    protected $hidden = ['id'];

    protected $attributes = ['first_order_only' => false, 'is_registration_offer' => false];

    protected static function booted(): void
    {
        static::creating(function (Promotion $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['type' => PromotionType::class, 'application_type' => PromotionApplicationType::class, 'discount_type' => DiscountType::class, 'discount_value' => 'decimal:2', 'maximum_discount' => 'decimal:2', 'minimum_subtotal' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'is_stackable' => 'boolean', 'priority' => 'integer', 'used_count' => 'integer', 'usage_limit' => 'integer', 'usage_limit_per_user' => 'integer', 'first_order_only' => 'boolean', 'is_registration_offer' => 'boolean'];
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'promotion_branches')->withTrashed();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promotion_categories');
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublicOffers(Builder $query, ?int $branchId = null): Builder
    {
        return $query->currentlyValid()->where('type', PromotionType::Automatic->value)
            ->where(fn (Builder $q): Builder => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->when($branchId !== null, fn (Builder $q): Builder => $q->forBranch($branchId));
    }

    public function scopeCurrentlyValid(Builder $query): Builder
    {
        return $query->active()->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** An empty branch target set means every branch. */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q->whereDoesntHave('branches')->orWhereHas('branches', fn (Builder $b): Builder => $b->where('branches.id', $branchId)));
    }
}
