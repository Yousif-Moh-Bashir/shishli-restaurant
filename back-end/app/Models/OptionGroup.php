<?php

namespace App\Models;

use App\Enums\OptionGroupType;
use Database\Factories\OptionGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'type', 'is_required', 'min_select', 'max_select', 'sort_order', 'is_active'])]
#[Hidden(['id', 'deleted_at'])]
class OptionGroup extends Model
{
    /** @use HasFactory<OptionGroupFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (OptionGroup $group): void {
            $group->uuid ??= (string) Str::uuid();
        });
        static::saving(function (OptionGroup $group): void {
            if (! filled($group->slug)) {
                $base = substr(Str::slug($group->name), 0, 160) ?: 'option-group-'.Str::lower(Str::random(8));
                $slug = $base;
                $suffix = 2;
                while (static::withTrashed()->where('slug', $slug)->when($group->exists,
                    fn (Builder $query): Builder => $query->whereKeyNot($group->getKey()))->exists()) {
                    $slug = $base.'-'.$suffix++;
                }
                $group->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function childRouteBindingRelationshipName(mixed $childType): string
    {
        return $childType === 'optionValue' ? 'values' : parent::childRouteBindingRelationshipName($childType);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<OptionValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(OptionValue::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_groups')
            ->withPivot(['sort_order', 'is_required_override', 'min_select_override', 'max_select_override'])
            ->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OptionGroupType::class,
            'is_required' => 'boolean',
            'min_select' => 'integer',
            'max_select' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
