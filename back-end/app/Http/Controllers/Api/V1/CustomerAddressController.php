<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Addresses\CreateCustomerAddressAction;
use App\Actions\Addresses\DeleteCustomerAddressAction;
use App\Actions\Addresses\SetDefaultCustomerAddressAction;
use App\Actions\Addresses\UpdateCustomerAddressAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCustomerAddressRequest;
use App\Http\Requests\Api\V1\UpdateCustomerAddressRequest;
use App\Http\Resources\Api\V1\CustomerAddressResource;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(CustomerAddressResource::collection($request->user()->addresses()->orderByDesc('is_default')->orderByDesc('id')->paginate(20)));
    }

    public function store(StoreCustomerAddressRequest $request, CreateCustomerAddressAction $action): JsonResponse
    {
        return ApiResponse::success(data: new CustomerAddressResource($action->handle($request->user(), $request->validated())), statusCode: 201);
    }

    public function show(CustomerAddress $address): JsonResponse
    {
        return ApiResponse::success(data: new CustomerAddressResource($address));
    }

    public function update(UpdateCustomerAddressRequest $request, CustomerAddress $address, UpdateCustomerAddressAction $action): JsonResponse
    {
        return ApiResponse::success(data: new CustomerAddressResource($action->handle($request->user(), $address->uuid, $request->validated())));
    }

    public function destroy(Request $request, CustomerAddress $address, DeleteCustomerAddressAction $action): JsonResponse
    {
        $action->handle($request->user(), $address->uuid);

        return ApiResponse::success(message: 'تم حذف العنوان.');
    }

    public function setDefault(Request $request, CustomerAddress $address, SetDefaultCustomerAddressAction $action): JsonResponse
    {
        return ApiResponse::success(data: new CustomerAddressResource($action->handle($request->user(), $address->uuid)));
    }
}
