<?php

namespace App\Models;

use Database\Factories\OptionValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['option_group_id', 'name', 'slug', 'price_modifier', 'is_default', 'is_active', 'sort_order'])]
#[Hidden(['id', 'option_group_id', 'deleted_at'])]
class OptionValue extends Model
{
    /** @use HasFactory<OptionValueFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (OptionValue $value): void {
            $value->uuid ??= (string) Str::uuid();
        });
        static::saving(function (OptionValue $value): void {
            if (! filled($value->slug)) {
                $base = substr(Str::slug($value->name), 0, 160) ?: 'option-'.Str::lower(Str::random(8));
                $slug = $base;
                $suffix = 2;
                while (static::withTrashed()->where('option_group_id', $value->option_group_id)->where('slug', $slug)
                    ->when($value->exists, fn (Builder $query): Builder => $query->whereKeyNot($value->getKey()))->exists()) {
                    $slug = $base.'-'.$suffix++;
                }
                $value->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
