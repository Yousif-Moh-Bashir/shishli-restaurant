<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListGuestCustomersRequest;
use App\Http\Resources\Api\V1\GuestCustomerSummaryResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Services\Customers\CustomerOrderQuery;
use App\Services\Customers\GuestCustomerQuery;
use Illuminate\Http\JsonResponse;

class GuestCustomerController extends Controller
{
    public function index(ListGuestCustomersRequest $request, GuestCustomerQuery $guests): JsonResponse
    {
        return ApiResponse::paginated(GuestCustomerSummaryResource::collection($guests->summaries($request->validated())->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function orders(ListGuestCustomersRequest $request, GuestCustomerQuery $guests, CustomerOrderQuery $orders): JsonResponse
    {
        return ApiResponse::paginated(OrderResource::collection($orders->apply($guests->orders($request->validated('phone')), $request->validated())->paginate($request->integer('per_page', 20))->withQueryString()));
    }
}
