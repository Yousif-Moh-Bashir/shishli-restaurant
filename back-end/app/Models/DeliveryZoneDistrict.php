<?php

namespace App\Models;

use App\Services\AddressNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryZoneDistrict extends Model
{
    protected $fillable = ['district_name'];

    protected $hidden = ['id', 'delivery_zone_id'];

    protected static function booted(): void
    {
        static::saving(function (DeliveryZoneDistrict $district): void {
            $district->normalized_name = AddressNormalizer::normalize($district->district_name);
        });
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }
}
