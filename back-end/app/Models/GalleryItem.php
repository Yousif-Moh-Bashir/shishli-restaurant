<?php

namespace App\Models;

use App\Services\CmsContentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class GalleryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['title', 'description', 'image', 'alt_text', 'sort_order', 'is_active', 'published_at', 'created_by', 'updated_by'];

    protected $hidden = ['id', 'page_id', 'created_by', 'updated_by', 'deleted_at', 'image', 'mobile_image', 'og_image'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (GalleryItem $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saved(function (GalleryItem $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
        static::deleted(function (GalleryItem $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isPublished(): bool
    {
        return $this->is_active && $this->published_at !== null && $this->published_at->lessThanOrEqualTo(now());
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
