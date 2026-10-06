<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Customers\ActivateCustomerAction;
use App\Actions\Customers\DeactivateCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListCustomersRequest;
use App\Http\Requests\Api\V1\ListOrdersRequest;
use App\Http\Resources\Api\V1\AdminCustomerResource;
use App\Http\Resources\Api\V1\AdminCustomerSummaryResource;
use App\Http\Resources\Api\V1\CustomerAddressResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Services\Admin\AdminCustomerFilters;
use App\Services\Customers\CustomerLookupService;
use App\Services\Customers\CustomerOrderQuery;
use App\Services\Customers\CustomerStatisticsService;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function __construct(private CustomerLookupService $customers, private CustomerStatisticsService $statistics) {}

    public function index(ListCustomersRequest $request): JsonResponse
    {
        return ApiResponse::paginated(AdminCustomerSummaryResource::collection($this->customers->search(AdminCustomerFilters::fromArray($request->validated())->values)->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function show(string $customer): JsonResponse
    {
        $user = $this->statistics->loadProfile($this->customers->find($customer));
        $user->load(['orders' => fn ($query) => $query->with('branch')->orderByDesc('placed_at')->orderByDesc('id')->limit(5)]);

        return ApiResponse::success(data: new AdminCustomerResource($user));
    }

    public function orders(ListOrdersRequest $request, string $customer, CustomerOrderQuery $orders): JsonResponse
    {
        return ApiResponse::paginated(OrderResource::collection($orders->apply($this->customers->find($customer)->orders()->getQuery(), $request->validated())->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function addresses(string $customer): JsonResponse
    {
        return ApiResponse::paginated(CustomerAddressResource::collection($this->customers->find($customer)->addresses()->orderByDesc('is_default')->orderByDesc('id')->paginate(20)));
    }

    public function activate(string $customer, ActivateCustomerAction $activate): JsonResponse
    {
        return ApiResponse::success(data: new AdminCustomerResource($this->statistics->loadProfile($activate->handle($this->customers->find($customer)))));
    }

    public function deactivate(string $customer, DeactivateCustomerAction $deactivate): JsonResponse
    {
        return ApiResponse::success(data: new AdminCustomerResource($this->statistics->loadProfile($deactivate->handle($this->customers->find($customer)))));
    }
}
