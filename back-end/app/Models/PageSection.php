<?php

namespace App\Models;

use App\Enums\PageSectionType;
use App\Services\CmsContentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PageSection extends Model
{
    use HasFactory;

    protected $fillable = ['page_id', 'key', 'type', 'title', 'subtitle', 'content', 'data', 'sort_order', 'is_active'];

    protected $hidden = ['id', 'page_id', 'created_by', 'updated_by', 'deleted_at', 'image', 'mobile_image', 'og_image'];

    protected function casts(): array
    {
        return ['type' => PageSectionType::class, 'data' => 'array', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (PageSection $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saved(function (PageSection $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
        static::deleted(function (PageSection $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
