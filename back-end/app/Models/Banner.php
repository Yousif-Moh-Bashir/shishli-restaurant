<?php

namespace App\Models;

use App\Enums\BannerPlacement;
use App\Services\CmsContentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Banner extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['placement', 'title', 'subtitle', 'image', 'mobile_image', 'button_text', 'button_url', 'secondary_button_text', 'secondary_button_url', 'starts_at', 'ends_at', 'sort_order', 'is_active', 'created_by', 'updated_by'];

    protected $hidden = ['id', 'page_id', 'created_by', 'updated_by', 'deleted_at', 'image', 'mobile_image', 'og_image'];

    protected function casts(): array
    {
        return ['placement' => BannerPlacement::class, 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Banner $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saved(function (Banner $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
        static::deleted(function (Banner $model): void {
            app(CmsContentService::class)->invalidate($model);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isPublished(): bool
    {
        return $this->is_active && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo(now())) && ($this->ends_at === null || $this->ends_at->greaterThanOrEqualTo(now()));
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
