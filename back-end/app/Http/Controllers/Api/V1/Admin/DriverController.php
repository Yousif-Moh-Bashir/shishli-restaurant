<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Drivers\ReassignDeliveryAction;
use App\Actions\Drivers\SettleDriverCashAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DriverRequest;
use App\Http\Requests\Api\V1\Admin\DriverSettlementRequest;
use App\Http\Requests\Api\V1\Admin\ReassignDeliveryRequest;
use App\Http\Requests\Api\V1\DriverListRequest;
use App\Http\Resources\Api\V1\DriverCollectionResource;
use App\Http\Resources\Api\V1\DriverResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\User;
use App\Services\Drivers\DriverManagementService;
use App\Services\Drivers\DriverOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class DriverController extends Controller
{
    public function index(DriverListRequest $request): JsonResponse
    {
        $query = $this->query();
        if ($request->filled('branch_uuid')) {
            $query->whereHas('driverBranch', fn (Builder $query): Builder => $query->where('uuid', $request->validated('branch_uuid')));
        }
        if ($request->has('available')) {
            $query->where('driver_available', $request->boolean('available'))->where('driver_enabled', true)->where('is_active', true);
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        return ApiResponse::paginated(DriverResource::collection($query->orderBy('name')->paginate($request->integer('per_page', 20))));
    }

    public function show(User $driver): JsonResponse
    {
        return ApiResponse::success(new DriverResource($this->query()->whereKey($driver->id)->firstOrFail()));
    }

    public function store(DriverRequest $request, DriverManagementService $management): JsonResponse
    {
        $driver = $management->save($request->user(), null, $request->validated());

        return ApiResponse::success(new DriverResource($this->query()->whereKey($driver->id)->firstOrFail()), statusCode: 201);
    }

    public function update(DriverRequest $request, User $driver, DriverManagementService $management): JsonResponse
    {
        $driver = $management->save($request->user(), $driver, $request->validated());

        return $this->show($driver);
    }

    public function collections(DriverListRequest $request, User $driver): JsonResponse
    {
        $query = $driver->driverCollections();
        if ($request->has('settled')) {
            $request->boolean('settled') ? $query->whereNotNull('settlement_id') : $query->whereNull('settlement_id');
        }

        return ApiResponse::paginated(DriverCollectionResource::collection($query->with(['order', 'settlement.cashier'])->orderByDesc('collected_at')->paginate($request->integer('per_page', 20))));
    }

    public function settle(DriverSettlementRequest $request, User $driver, SettleDriverCashAction $settle): JsonResponse
    {
        $settlement = $settle->handle($request->user(), $driver, $request->validated('collection_uuids'), (string) $request->validated('amount'), $request->validated('reason'));

        return ApiResponse::success(['id' => $settlement->uuid, 'amount' => $settlement->amount, 'currency' => $settlement->currency,
            'cashier' => $request->user()->name, 'settled_at' => $settlement->created_at->toISOString()]);
    }

    public function reassign(ReassignDeliveryRequest $request, Order $order, ReassignDeliveryAction $reassign): JsonResponse
    {
        $driver = User::role('driver')->where('uuid', $request->validated('driver_uuid'))->firstOrFail();
        $order = $reassign->handle($request->user(), $order, $driver, $request->validated('reason'));

        return ApiResponse::success(['id' => $order->uuid, 'driver' => ['id' => $driver->uuid, 'name' => $driver->name], 'delivery_status' => $order->delivery_status->value]);
    }

    private function query(): Builder
    {
        return User::role('driver')->with(['driverBranch', 'deliveries' => fn (HasMany $query): HasMany => $query
            ->whereIn('delivery_status', DriverOrderService::ACTIVE_STATUSES)->whereNotIn('status', ['completed', 'cancelled'])])
            ->withCount(['deliveries as delivered_count' => fn (Builder $query): Builder => $query->where('delivery_status', 'delivered')])
            ->withSum(['driverCollections as unsettled_cash' => fn (Builder $query): Builder => $query->whereNull('settlement_id')], 'amount');
    }
}
