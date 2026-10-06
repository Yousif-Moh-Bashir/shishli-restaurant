<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminListRequest as CmsPublicRequest;
use App\Http\Requests\Api\V1\CmsGalleryRequest;
use App\Http\Requests\Api\V1\CmsReorderRequest;
use App\Http\Resources\Api\V1\GalleryItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\GalleryItem;
use App\Services\Admin\AdminListQuery;
use App\Services\CmsWriteService;
use Illuminate\Http\JsonResponse;

class CmsGalleryController extends Controller
{
    public function index(CmsPublicRequest $request, AdminListQuery $lists): JsonResponse
    {
        return ApiResponse::paginated(GalleryItemResource::collection($lists->apply(GalleryItem::query(), $request->validated(), ['title', 'description'], ['sort_order', 'title', 'created_at', 'updated_at'], 'sort_order')->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function store(CmsGalleryRequest $request, CmsWriteService $writer): JsonResponse
    {
        return ApiResponse::success(new GalleryItemResource($writer->save(new GalleryItem, $request->validated(), $request->user())), statusCode: 201);
    }

    public function show(GalleryItem $galleryItem): JsonResponse
    {
        return ApiResponse::success(new GalleryItemResource($galleryItem));
    }

    public function update(CmsGalleryRequest $request, GalleryItem $galleryItem, CmsWriteService $writer): JsonResponse
    {
        return ApiResponse::success(new GalleryItemResource($writer->save($galleryItem, $request->validated(), $request->user())));
    }

    public function destroy(GalleryItem $galleryItem, CmsWriteService $writer): JsonResponse
    {
        $writer->delete($galleryItem);

        return ApiResponse::success();
    }

    public function reorder(CmsReorderRequest $request, CmsWriteService $writer): JsonResponse
    {
        $writer->reorder(GalleryItem::class, $request->validated('items'), $request->user());

        return ApiResponse::success();
    }
}
