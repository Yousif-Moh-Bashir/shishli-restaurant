<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\ConfigureInventoryAction;
use App\Actions\Inventory\InitializeInventoryAction;
use App\Actions\Inventory\ReceiveStockAction;
use App\Actions\Inventory\ReturnOrderStockAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\InventoryFilterRequest;
use App\Http\Requests\Api\V1\Admin\StockMovementFilterRequest;
use App\Http\Requests\Api\V1\Admin\StockMutationRequest;
use App\Http\Requests\Api\V1\Admin\StoreInventoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateInventoryRequest;
use App\Http\Resources\Api\V1\BranchInventoryResource;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Http\Responses\ApiResponse;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\StockMovement;
use App\Services\InventoryQueryService;
use App\Services\Reports\InventoryReportService;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function index(InventoryFilterRequest $request, InventoryQueryService $queries): JsonResponse
    {
        return ApiResponse::paginated(BranchInventoryResource::collection($queries->sorted($request->validated())->paginate($request->validated('per_page', 20))));
    }

    public function show(BranchInventory $inventory): JsonResponse
    {
        $inventory->load(['branch', 'product']);
        $inventory->setRelation('recentMovements', $inventory->movements()->with('createdBy')->orderByDesc('id')->limit(10)->get()
            ->each(fn (StockMovement $movement) => $movement->setRelation('inventory', $inventory)));

        return $this->inventoryResponse($inventory);
    }

    public function store(StoreInventoryRequest $request, InitializeInventoryAction $action): JsonResponse
    {
        return ApiResponse::success(data: new BranchInventoryResource($action->handle($request->validated(), $request->user())->load(['branch', 'product'])), statusCode: 201);
    }

    public function update(UpdateInventoryRequest $request, BranchInventory $inventory, ConfigureInventoryAction $action): JsonResponse
    {
        return $this->inventoryResponse($action->handle($inventory, $request->validated()));
    }

    public function receive(StockMutationRequest $request, BranchInventory $inventory, ReceiveStockAction $action): JsonResponse
    {
        return $this->inventoryResponse($action->handle($inventory, $request->validated('quantity'), $request->user(), $request->validated('reason'), $request->validated('reference_uuid')));
    }

    public function adjust(StockMutationRequest $request, BranchInventory $inventory, AdjustStockAction $action): JsonResponse
    {
        return $this->inventoryResponse($action->handle($inventory, $request->validated('quantity'), $request->user(), $request->validated('reason')));
    }

    public function waste(StockMutationRequest $request, BranchInventory $inventory, AdjustStockAction $action): JsonResponse
    {
        return $this->inventoryResponse($action->handle($inventory, $request->validated('quantity'), $request->user(), $request->validated('reason'), waste: true));
    }

    public function movements(StockMovementFilterRequest $request, InventoryQueryService $queries, ?BranchInventory $inventory = null): JsonResponse
    {
        return ApiResponse::paginated(StockMovementResource::collection($queries->movements($request->validated(), $inventory)->paginate($request->validated('per_page', 20))));
    }

    public function report(InventoryFilterRequest $request, InventoryReportService $reports): JsonResponse
    {
        return ApiResponse::success(data: $reports->get($request->validated()));
    }

    public function returnStock(StockMutationRequest $request, Order $order, ReturnOrderStockAction $action): JsonResponse
    {
        $action->handle($order, $request->user(), $request->validated('reason'));

        return ApiResponse::success(message: 'تم تسجيل الإرجاع اليدوي للمخزون.');
    }

    private function inventoryResponse(BranchInventory $inventory): JsonResponse
    {
        return ApiResponse::success(data: new BranchInventoryResource($inventory->load(['branch', 'product'])));
    }
}
