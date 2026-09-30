<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = ['id', 'parent_id', 'deleted_at'];

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            $category->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Category $category): void {
            if (! filled($category->slug)) {
                $base = Str::slug($category->name);
                $base = $base !== '' ? substr($base, 0, 160) : 'category-'.Str::lower(Str::random(8));
                $slug = $base;
                $suffix = 2;

                while (static::withTrashed()->where('slug', $slug)->when(
                    $category->exists,
                    fn (Builder $query): Builder => $query->whereKeyNot($category->getKey()),
                )->exists()) {
                    $slug = $base.'-'.$suffix++;
                }

                $category->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            Category::class,
            'parent_id'
        );
    }

    public function children(): HasMany
    {
        return $this->hasMany(
            Category::class,
            'parent_id'
        );
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
