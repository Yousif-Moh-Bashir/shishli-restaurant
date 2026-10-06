<?php

namespace App\Models;

use App\Enums\PageStatus;
use App\Services\CmsContentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Page extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['key', 'title', 'slug', 'status', 'meta_title', 'meta_description', 'canonical_url', 'og_image', 'published_at', 'created_by', 'updated_by'];

    protected $hidden = ['id', 'page_id', 'created_by', 'updated_by', 'deleted_at', 'image', 'mobile_image', 'og_image'];

    protected function casts(): array
    {
        return ['status' => PageStatus::class, 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Page $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saved(function (Page $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
        static::deleted(function (Page $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published && ($this->published_at === null || $this->published_at->lessThanOrEqualTo(now()));
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
