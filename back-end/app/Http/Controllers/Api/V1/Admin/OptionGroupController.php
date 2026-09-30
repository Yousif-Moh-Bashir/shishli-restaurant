<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Options\CreateOptionGroupAction;
use App\Actions\Options\DeleteOptionGroupAction;
use App\Actions\Options\UpdateOptionGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListOptionGroupsRequest;
use App\Http\Requests\Api\V1\Admin\StoreOptionGroupRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOptionGroupRequest;
use App\Http\Resources\Api\V1\OptionGroupResource;
use App\Http\Responses\ApiResponse;
use App\Models\OptionGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class OptionGroupController extends Controller
{
    public function index(ListOptionGroupsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = OptionGroup::query();
        if (isset($filters['search'])) {
            $query->where(fn (Builder $query): Builder => $query->where('name', 'like', '%'.$filters['search'].'%')
                ->orWhere('slug', 'like', '%'.$filters['search'].'%'));
        }
        foreach (['type', 'is_active'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        $groups = $query->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20))->appends($filters);

        return ApiResponse::paginated(OptionGroupResource::collection($groups));
    }

    public function store(StoreOptionGroupRequest $request, CreateOptionGroupAction $create): JsonResponse
    {
        return ApiResponse::success(data: new OptionGroupResource($create->handle($request->validated())), statusCode: 201);
    }

    public function show(OptionGroup $optionGroup): JsonResponse
    {
        return ApiResponse::success(data: new OptionGroupResource($optionGroup->load('values')));
    }

    public function update(UpdateOptionGroupRequest $request, OptionGroup $optionGroup, UpdateOptionGroupAction $update): JsonResponse
    {
        return ApiResponse::success(data: new OptionGroupResource($update->handle($optionGroup, $request->validated())));
    }

    public function destroy(OptionGroup $optionGroup, DeleteOptionGroupAction $delete): JsonResponse
    {
        $delete->handle($optionGroup);

        return ApiResponse::success(message: 'تم حذف مجموعة الخيارات بنجاح.');
    }
}
