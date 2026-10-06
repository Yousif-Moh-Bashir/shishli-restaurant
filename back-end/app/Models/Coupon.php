<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Coupon extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['promotion_id', 'code', 'is_active', 'usage_limit', 'usage_limit_per_user', 'starts_at', 'ends_at'];

    protected $hidden = ['id', 'promotion_id'];

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function setCodeAttribute(string $code): void
    {
        $this->attributes['code'] = self::normalizeCode($code);
    }

    protected static function booted(): void
    {
        static::creating(function (Coupon $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'used_count' => 'integer', 'usage_limit' => 'integer', 'usage_limit_per_user' => 'integer'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function isCurrentlyValid(): bool
    {
        $time = now()->startOfSecond();

        return ! $this->trashed() && $this->is_active && ($this->starts_at === null || $this->starts_at->lte($time)) && ($this->ends_at === null || $this->ends_at->gte($time));
    }
}
