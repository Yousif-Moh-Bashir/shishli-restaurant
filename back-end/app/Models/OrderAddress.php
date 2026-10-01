<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends Model
{
    use HasFactory;

    public const SNAPSHOT_FIELDS = ['recipient_name', 'phone', 'city', 'district', 'street', 'building_number', 'floor', 'apartment', 'landmark', 'notes', 'latitude', 'longitude'];

    protected $fillable = ['order_id', 'recipient_name', 'phone', 'city', 'district', 'street', 'building_number', 'floor', 'apartment', 'landmark', 'notes', 'latitude', 'longitude', 'delivery_zone_name', 'delivery_zone_uuid'];

    protected $hidden = ['id', 'order_id'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
