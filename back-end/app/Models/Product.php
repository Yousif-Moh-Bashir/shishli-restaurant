<?php

namespace App\Models;

use App\Services\MediaService;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['category_id', 'name', 'slug', 'sku', 'short_description', 'description', 'base_price', 'is_active', 'is_featured', 'is_available', 'sort_order', 'preparation_time'])]
#[Hidden(['id', 'category_id', 'deleted_at'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<array{disk: string, path: string}> */
    protected array $mediaPendingDeletion = [];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected static function booted(): void
    {
        static::forceDeleting(function (Product $product): void {
            $product->mediaPendingDeletion = $product->images()->get(['disk', 'path'])
                ->map(fn (ProductImage $image): array => ['disk' => $image->disk, 'path' => $image->path])->all();
        });

        static::forceDeleted(function (Product $product): void {
            $files = $product->mediaPendingDeletion;
            DB::afterCommit(function () use ($files): void {
                $media = app(MediaService::class);
                foreach ($files as $file) {
                    $media->delete($file['path'], $file['disk']);
                }
            });
        });

        static::saving(function (Product $product): void {
            if (! filled($product->slug)) {
                $base = Str::slug($product->name);
                $base = $base !== '' ? substr($base, 0, 180) : 'product-'.Str::lower(Str::random(8));
                $slug = $base;
                $suffix = 2;

                while (static::withTrashed()->where('slug', $slug)->when(
                    $product->exists,
                    fn (Builder $query): Builder => $query->whereKeyNot($product->getKey()),
                )->exists()) {
                    $slug = $base.'-'.$suffix++;
                }

                $product->slug = $slug;
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleInMenu(Builder $query): Builder
    {
        return $query->active()
            ->whereHas('category', fn (Builder $category): Builder => $category->active());
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * @return BelongsToMany<OptionGroup, $this>
     */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'product_option_groups')
            ->withPivot(['sort_order', 'is_required_override', 'min_select_override', 'max_select_override'])
            ->withTimestamps()->orderByPivot('sort_order')->orderBy('option_groups.id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_products')->using(BranchProduct::class)
            ->withPivot(['id', 'price_override', 'is_available'])->withTimestamps();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
            'preparation_time' => 'integer',
        ];
    }
}
