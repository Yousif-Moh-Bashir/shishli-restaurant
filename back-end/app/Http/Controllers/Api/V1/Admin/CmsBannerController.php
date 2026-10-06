<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminListRequest as CmsPublicRequest;
use App\Http\Requests\Api\V1\CmsBannerRequest;
use App\Http\Requests\Api\V1\CmsReorderRequest;
use App\Http\Resources\Api\V1\BannerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Banner;
use App\Services\Admin\AdminListQuery;
use App\Services\CmsWriteService;
use Illuminate\Http\JsonResponse;

class CmsBannerController extends Controller
{
    public function index(CmsPublicRequest $request, AdminListQuery $lists): JsonResponse
    {
        return ApiResponse::paginated(BannerResource::collection($lists->apply(Banner::query(), $request->validated(), ['title', 'subtitle'], ['sort_order', 'title', 'created_at', 'updated_at'], 'sort_order')->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function store(CmsBannerRequest $request, CmsWriteService $writer): JsonResponse
    {
        return ApiResponse::success(new BannerResource($writer->save(new Banner, $request->validated(), $request->user())), statusCode: 201);
    }

    public function show(Banner $banner): JsonResponse
    {
        return ApiResponse::success(new BannerResource($banner));
    }

    public function update(CmsBannerRequest $request, Banner $banner, CmsWriteService $writer): JsonResponse
    {
        return ApiResponse::success(new BannerResource($writer->save($banner, $request->validated(), $request->user())));
    }

    public function destroy(Banner $banner, CmsWriteService $writer): JsonResponse
    {
        $writer->delete($banner);

        return ApiResponse::success();
    }

    public function reorder(CmsReorderRequest $request, CmsWriteService $writer): JsonResponse
    {
        $writer->reorder(Banner::class, $request->validated('items'), $request->user());

        return ApiResponse::success();
    }
}
