<?php

namespace App\Models;

use App\Enums\DeliveryZoneType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DeliveryZone extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'type', 'is_active', 'delivery_fee', 'minimum_order', 'free_delivery_threshold', 'estimated_min_minutes', 'estimated_max_minutes', 'priority', 'center_latitude', 'center_longitude', 'radius_km'];

    protected $hidden = ['id', 'branch_id', 'deleted_at'];

    protected static function booted(): void
    {
        static::creating(function (DeliveryZone $zone): void {
            $zone->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['type' => DeliveryZoneType::class, 'is_active' => 'boolean', 'delivery_fee' => 'decimal:2', 'minimum_order' => 'decimal:2', 'free_delivery_threshold' => 'decimal:2', 'center_latitude' => 'decimal:7', 'center_longitude' => 'decimal:7', 'radius_km' => 'decimal:2', 'priority' => 'integer', 'estimated_min_minutes' => 'integer', 'estimated_max_minutes' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function districts(): HasMany
    {
        return $this->hasMany(DeliveryZoneDistrict::class)->orderBy('id');
    }
}
