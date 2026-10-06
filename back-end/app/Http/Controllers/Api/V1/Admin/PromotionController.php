<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Promotions\CreatePromotionAction;
use App\Actions\Promotions\DeletePromotionAction;
use App\Actions\Promotions\UpdatePromotionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\PromotionFilterRequest;
use App\Http\Requests\Api\V1\Admin\StorePromotionRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePromotionRequest;
use App\Http\Resources\Api\V1\PromotionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\Promotion;
use App\Services\Admin\AdminListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class PromotionController extends Controller
{
    public function index(PromotionFilterRequest $request, AdminListQuery $lists): JsonResponse
    {
        $data = $request->validated();
        $query = Promotion::with(['branches', 'products', 'categories']);
        foreach (['type', 'discount_type'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['branch_uuid'])) {
            $branch = Branch::where('uuid', $data['branch_uuid'])->first();
            $branch === null ? $query->whereRaw('1=0') : $query->forBranch($branch->id);
        }
        if (isset($data['status'])) {
            match ($data['status']) {
                'active' => $query->currentlyValid(), 'future' => $query->active()->where('starts_at', '>', now()), 'expired' => $query->where('ends_at', '<', now()), 'inactive' => $query->where('is_active', false)
            };
        }
        if (isset($data['date_from'])) {
            $query->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $data['date_from'].' 00:00:00'));
        }
        if (isset($data['date_to'])) {
            $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', CarbonImmutable::parse($data['date_to'])->addDay()->startOfDay()));
        }

        return ApiResponse::paginated(PromotionResource::collection($lists->apply($query, $data, ['name', 'description'], ['name', 'priority', 'created_at', 'starts_at', 'ends_at', 'used_count'], 'priority', 'desc')->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function store(StorePromotionRequest $request, CreatePromotionAction $create): JsonResponse
    {
        return ApiResponse::success(data: new PromotionResource($create->handle($request->validated())), statusCode: 201);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return ApiResponse::success(data: new PromotionResource($promotion->load(['branches', 'products', 'categories'])));
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion, UpdatePromotionAction $update): JsonResponse
    {
        return ApiResponse::success(data: new PromotionResource($update->handle($promotion, $request->validated())));
    }

    public function destroy(Promotion $promotion, DeletePromotionAction $delete): JsonResponse
    {
        $delete->handle($promotion);

        return ApiResponse::success(message: 'تم حذف العرض.');
    }
}
