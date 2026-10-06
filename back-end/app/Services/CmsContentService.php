<?php

namespace App\Services;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use App\Models\GalleryItem;
use App\Models\Page;
use App\Models\PageSection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CmsContentService
{
    public function page(string $key): Page
    {
        abort_unless(array_key_exists($key, app(CmsSectionDefinition::class)->pages()), 404);
        $page = $this->fragment('cms:page:'.$key, fn (): ?Page => Page::where('key', $key)->with('sections')->first());
        abort_unless($page instanceof Page && $page->isPublished(), 404);
        $page = clone $page;
        $page->setRelation('sections', $page->sections->filter(fn (PageSection $section): bool => $section->is_active && (app(CmsSectionDefinition::class)->sections($key)[$section->key] ?? null) === $section->type)->values());

        return $page;
    }

    public function banners(BannerPlacement $placement): Collection
    {
        return $this->fragment('cms:banners:'.$placement->value, fn (): Collection => Banner::where('placement', $placement->value)->orderBy('sort_order')->orderBy('id')->get())->filter(fn (Banner $banner): bool => $banner->isPublished())->values();
    }

    public function galleryPreview(int $limit): Collection
    {
        return $this->fragment('cms:gallery:preview:'.$limit, fn (): Collection => GalleryItem::where('is_active', true)->where('published_at', '<=', now())->orderBy('sort_order')->orderBy('id')->limit($limit)->get(), $this->galleryTtl())->filter(fn (GalleryItem $item): bool => $item->isPublished())->values();
    }

    private function galleryTtl(): int
    {
        $next = GalleryItem::where('is_active', true)->where('published_at', '>', now())->min('published_at');

        return $next === null ? 300 : max(1, min(300, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($next)))));
    }

    private function fragment(string $key, callable $loader, int $ttl = 300): mixed
    {
        return Cache::lock($key.':lock', 15)->block(5, fn (): mixed => Cache::remember($key, $ttl, $loader));
    }

    public function invalidate(Model $model): void
    {
        $keys = match (true) {
            $model instanceof Page => ['cms:page:'.$model->key],
            $model instanceof PageSection => ['cms:page:'.($model->relationLoaded('page') ? $model->page->key : Page::whereKey($model->page_id)->value('key'))],
            $model instanceof Banner => array_map(fn (BannerPlacement $placement): string => 'cms:banners:'.$placement->value, BannerPlacement::cases()),
            $model instanceof GalleryItem => array_map(fn (int $limit): string => 'cms:gallery:preview:'.$limit, range(1, 12)),
            default => []
        };
        DB::afterCommit(function () use ($keys): void {
            foreach ($keys as $key) {
                try {
                    Cache::lock($key.':lock', 15)->block(5, fn (): bool => Cache::forget($key));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });
    }
}
