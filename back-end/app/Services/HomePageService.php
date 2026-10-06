<?php

namespace App\Services;

use App\Enums\BannerPlacement;
use App\Http\Resources\Api\V1\BannerResource;
use App\Http\Resources\Api\V1\BranchBusinessHoursResource;
use App\Http\Resources\Api\V1\BranchResource;
use App\Http\Resources\Api\V1\GalleryItemResource;
use App\Http\Resources\Api\V1\MenuProductResource;
use App\Http\Resources\Api\V1\PageResource;
use App\Http\Resources\Api\V1\PublicPromotionResource;
use App\Models\Branch;
use App\Models\Promotion;

class HomePageService
{
    public function __construct(private CmsContentService $cms, private MenuService $menu, private RestaurantSettingsService $settings, private BranchAvailabilityService $availability) {}

    public function get(array $filters): array
    {
        $page = $this->cms->page('home');
        $branches = Branch::where('is_active', true)->with(['businessHours', 'closures'])->orderBy('sort_order')->orderBy('id')->get();
        $branch = isset($filters['branch']) ? $this->menu->branch($filters['branch']) : null;
        if ($branch !== null) {
            $branch = $branches->firstWhere('id', $branch->id);
        }
        $sections = $page->sections->keyBy('key');
        $products = collect();
        if ($branch !== null && $sections->has('featured_menu')) {
            $products = $this->menu->categories($branch, ['featured' => true])->flatMap(fn ($category) => $category->menuProducts)->take($sections['featured_menu']->data['limit'] ?? 6)->values();
        }
        $offers = collect();
        if ($sections->has('offers')) {
            $offers = Promotion::publicOffers($branch?->id)->orderByDesc('priority')->orderBy('uuid')->limit($sections['offers']->data['limit'] ?? 6)->get();
        }
        $gallery = $sections->has('gallery_preview') ? $this->cms->galleryPreview($sections['gallery_preview']->data['limit'] ?? 6) : collect();

        return ['page' => new PageResource($page), 'banners' => BannerResource::collection($sections->has('hero') ? $this->cms->banners(BannerPlacement::HomeHero) : collect()),
            'secondary_banners' => BannerResource::collection($this->cms->banners(BannerPlacement::HomeSecondary)),
            'featured_products' => MenuProductResource::collection($products), 'active_offers' => PublicPromotionResource::collection($offers),
            'gallery_preview' => GalleryItemResource::collection($gallery), 'restaurant' => $this->settings->publicSettings(),
            'branches' => $branches->map(fn (Branch $item): array => ['branch' => new BranchResource($item), 'business_hours' => new BranchBusinessHoursResource($item), 'status' => $this->availability->evaluate($item)->toArray()])->values(), 'branch_uuid' => $branch?->uuid];
    }
}
