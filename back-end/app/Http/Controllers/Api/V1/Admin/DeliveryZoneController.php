<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Delivery\CreateDeliveryZoneAction;
use App\Actions\Delivery\DeleteDeliveryZoneAction;
use App\Actions\Delivery\UpdateDeliveryZoneAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDeliveryZoneRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDeliveryZoneRequest;
use App\Http\Resources\Api\V1\DeliveryZoneResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\DeliveryZone;
use Illuminate\Http\JsonResponse;

class DeliveryZoneController extends Controller
{
    public function index(Branch $branch): JsonResponse
    {
        return ApiResponse::paginated(DeliveryZoneResource::collection($branch->deliveryZones()->with('districts')->orderByDesc('priority')->orderBy('id')->paginate(20)));
    }

    public function store(StoreDeliveryZoneRequest $request, Branch $branch, CreateDeliveryZoneAction $action): JsonResponse
    {
        return ApiResponse::success(data: new DeliveryZoneResource($action->handle($branch, $request->validated())), statusCode: 201);
    }

    public function show(Branch $branch, DeliveryZone $deliveryZone): JsonResponse
    {
        return ApiResponse::success(data: new DeliveryZoneResource($deliveryZone->load('districts')));
    }

    public function update(UpdateDeliveryZoneRequest $request, Branch $branch, DeliveryZone $deliveryZone, UpdateDeliveryZoneAction $action): JsonResponse
    {
        return ApiResponse::success(data: new DeliveryZoneResource($action->handle($branch, $deliveryZone->uuid, $request->validated())));
    }

    public function destroy(Branch $branch, DeliveryZone $deliveryZone, DeleteDeliveryZoneAction $action): JsonResponse
    {
        $action->handle($branch, $deliveryZone->uuid);

        return ApiResponse::success(message: 'تم حذف منطقة التوصيل.');
    }
}
