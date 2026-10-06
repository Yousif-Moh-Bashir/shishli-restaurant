<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminListRequest as CmsPublicRequest;
use App\Http\Requests\Api\V1\CmsPageUpdateRequest;
use App\Http\Requests\Api\V1\CmsSectionUpdateRequest;
use App\Http\Resources\Api\V1\PageResource;
use App\Http\Resources\Api\V1\PageSectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Page;
use App\Models\PageSection;
use App\Services\Admin\AdminListQuery;
use App\Services\CmsWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class CmsPageController extends Controller
{
    public function index(CmsPublicRequest $request, AdminListQuery $lists): JsonResponse
    {
        return ApiResponse::paginated(PageResource::collection($lists->apply(Page::with('sections'), Arr::except($request->validated(), ['active', 'is_active']), ['key', 'title'], ['key', 'title', 'created_at', 'updated_at'], 'key')->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function show(Page $page): JsonResponse
    {
        return ApiResponse::success(new PageResource($page->load('sections')));
    }

    public function update(CmsPageUpdateRequest $request, Page $page, CmsWriteService $writer): JsonResponse
    {
        return ApiResponse::success(new PageResource($writer->save($page, $request->validated(), $request->user())->load('sections')));
    }

    public function sections(Page $page): JsonResponse
    {
        return ApiResponse::success(PageSectionResource::collection($page->sections));
    }

    public function updateSection(CmsSectionUpdateRequest $request, Page $page, PageSection $section, CmsWriteService $writer): JsonResponse
    {
        abort_unless($section->page_id === $page->id, 404);

        return ApiResponse::success(new PageSectionResource($writer->save($section, $request->validated(), $request->user())));
    }
}
