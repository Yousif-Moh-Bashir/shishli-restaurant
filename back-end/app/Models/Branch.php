<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = ['id'];

    protected $fillable = [
        'name',
        'slug',
        'phone',
        'whatsapp',
        'city',
        'district',
        'address',
        'latitude',
        'longitude',
        'is_active',
        'accepts_orders',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'accepts_orders' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            $branch->uuid ??= (string) Str::uuid();

            if (empty($branch->slug)) {
                $branch->slug = (Str::slug(Str::limit($branch->name, 100, '')) ?: 'branch').'-'.$branch->uuid;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
