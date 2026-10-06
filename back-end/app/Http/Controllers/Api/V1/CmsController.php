<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BannerPlacement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CmsPublicRequest;
use App\Http\Resources\Api\V1\BannerResource;
use App\Http\Resources\Api\V1\GalleryItemResource;
use App\Http\Resources\Api\V1\PageResource;
use App\Http\Responses\ApiResponse;
use App\Models\GalleryItem;
use App\Services\CmsContentService;
use App\Services\HomePageService;
use Illuminate\Http\JsonResponse;

class CmsController extends Controller
{
    public function page(string $pageKey, CmsContentService $cms): JsonResponse
    {
        return ApiResponse::success(new PageResource($cms->page($pageKey)));
    }

    public function banners(CmsPublicRequest $request, CmsContentService $cms): JsonResponse
    {
        return ApiResponse::success(BannerResource::collection($cms->banners(BannerPlacement::from($request->input('placement', 'home_hero')))));
    }

    public function gallery(CmsPublicRequest $request): JsonResponse
    {
        $items = GalleryItem::where('is_active', true)->where('published_at', '<=', now())->orderBy('sort_order')->orderBy('id')->paginate($request->integer('per_page', 24));

        return ApiResponse::paginated(GalleryItemResource::collection($items));
    }

    public function home(CmsPublicRequest $request, HomePageService $home): JsonResponse
    {
        return ApiResponse::success($home->get($request->validated()));
    }
}
