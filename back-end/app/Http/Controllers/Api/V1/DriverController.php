<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Drivers\AcceptDeliveryOrderAction;
use App\Actions\Drivers\CollectDriverCashAction;
use App\Actions\Drivers\TransitionDeliveryAction;
use App\Actions\Payments\CollectCashPaymentAction;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DriverListRequest;
use App\Http\Requests\Api\V1\DriverOperationRequest;
use App\Http\Resources\Api\V1\DriverCollectionResource;
use App\Http\Resources\Api\V1\DriverOrderResource;
use App\Http\Resources\Api\V1\DriverResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Drivers\DriverManagementService;
use App\Services\Drivers\DriverOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DriverController extends Controller
{
    public function me(Request $request, DriverOrderService $orders): JsonResponse
    {
        $driver = $request->user();
        $orders->authorize($driver);

        return ApiResponse::success(new DriverResource($driver->load('driverBranch')));
    }

    public function availability(DriverOperationRequest $request, DriverManagementService $management): JsonResponse
    {
        return ApiResponse::success(new DriverResource($management->availability($request->user(), $request->boolean('available'))->load('driverBranch')));
    }

    public function index(DriverListRequest $request, DriverOrderService $orders): JsonResponse
    {
        $driver = $request->user();
        $orders->authorize($driver);
        $context = $orders->listingContext($driver);
        $currentDay = $orders->businessDay($driver);
        if ($context['active_order'] !== null) {
            $context['active_order']['is_previous_day'] = $context['active_order']['placed_at'] !== null
                && CarbonImmutable::parse($context['active_order']['placed_at'])->lessThan($currentDay['start']);
        }
        $history = $request->input('period', 'current') === 'history';
        $window = $history && $request->filled('work_date') ? $orders->businessDay($driver, $request->validated('work_date')) : $currentDay;
        if ($history && $request->filled('work_date') && $window['start']->greaterThanOrEqualTo($currentDay['start'])) {
            throw ValidationException::withMessages(['work_date' => 'اختر يوم عمل سابقًا. طلبات يوم العمل الحالي متاحة في تبويب اليوم.']);
        }
        $context['can_accept'] = ! $history && $context['can_accept'];
        $context['work_day'] = ['date' => $window['date'], 'timezone' => $window['timezone'],
            'from' => $window['start']->toISOString(), 'until' => $window['end']->toISOString(),
            'period' => $history ? 'history' : 'current', 'selected_date' => $history ? $request->input('work_date') : null,
            'latest_history_date' => CarbonImmutable::parse($currentDay['date'])->subDay()->toDateString()];
        request()->attributes->set('driver_listing_context', $context);
        $query = $request->input('scope', 'available') === 'mine'
            ? Order::where('driver_id', $driver->id)->where('branch_id', $driver->driver_branch_id)
            : $orders->candidates($driver);

        if ($history && ! $request->filled('work_date')) {
            $query->where('placed_at', '<', $currentDay['start']);
        } else {
            $query->where('placed_at', '>=', $window['start'])->where('placed_at', '<', $window['end']);
        }

        if ($request->input('scope', 'available') === 'mine') {
            $query->orderByDesc('placed_at')->orderByDesc('id');
        } else {
            $query->orderByRaw("CASE WHEN status = 'ready' THEN 0 ELSE 1 END")->orderBy('placed_at')->orderBy('id');
        }

        return DriverOrderResource::collection($query->with(['branch', 'address'])->paginate($request->integer('per_page', 20)))
            ->additional(['success' => true, 'message' => 'Success', 'errors' => null, 'driver_context' => $context])->response();
    }

    public function show(Request $request, Order $order, DriverOrderService $orders): JsonResponse
    {
        $orders->owned($request->user(), $order);

        return $this->orderResponse($order);
    }

    public function accept(DriverOperationRequest $request, Order $order, AcceptDeliveryOrderAction $accept): JsonResponse
    {
        return $this->orderResponse($accept->handle($request->user(), $order));
    }

    public function transition(DriverOperationRequest $request, Order $order, TransitionDeliveryAction $transition): JsonResponse
    {
        return $this->orderResponse($transition->handle($request->user(), $order, DeliveryStatus::from($request->validated('status')), $request->validated('reason')));
    }

    public function collect(DriverOperationRequest $request, Order $order, CollectDriverCashAction $collect): JsonResponse
    {
        return ApiResponse::success(new DriverCollectionResource($collect->handle($request->user(), $order, (string) $request->validated('amount'))->load(['order', 'settlement.cashier'])));
    }

    public function confirmNetwork(DriverOperationRequest $request, Order $order, CollectCashPaymentAction $collect): JsonResponse
    {
        $collect->handle($order, $request->user());

        return $this->orderResponse($order->fresh());
    }

    public function collections(DriverListRequest $request, DriverOrderService $orders): JsonResponse
    {
        $orders->authorize($request->user());
        $query = $request->user()->driverCollections();
        if ($request->has('settled')) {
            $request->boolean('settled') ? $query->whereNotNull('settlement_id') : $query->whereNull('settlement_id');
        }

        return ApiResponse::paginated(DriverCollectionResource::collection($query->with(['order', 'settlement.cashier'])->orderByDesc('collected_at')->paginate($request->integer('per_page', 20))));
    }

    private function orderResponse(Order $order): JsonResponse
    {
        return ApiResponse::success(new DriverOrderResource($order->load(['branch', 'address', 'deliveryHistory.actor'])));
    }
}
