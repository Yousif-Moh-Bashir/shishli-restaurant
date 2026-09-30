<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomerAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['label', 'recipient_name', 'phone', 'city', 'district', 'street', 'building_number', 'floor', 'apartment', 'landmark', 'notes', 'latitude', 'longitude', 'is_default'];

    protected $hidden = ['id', 'user_id', 'default_user_id', 'deleted_at'];

    protected static function booted(): void
    {
        static::creating(function (CustomerAddress $address): void {
            $address->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'is_default' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
