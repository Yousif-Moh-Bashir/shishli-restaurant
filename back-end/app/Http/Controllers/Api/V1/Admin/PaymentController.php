<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Payments\CollectCashPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPaymentsRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Http\Resources\Api\V1\PaymentTransactionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(ListPaymentsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Payment::query()->with('order');
        foreach (['status', 'method', 'provider'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['order_number'])) {
            $query->whereHas('order', fn (Builder $query) => $query->where('order_number', $data['order_number']));
        }
        if (isset($data['date_from'])) {
            $query->where('created_at', '>=', $data['date_from'].' 00:00:00');
        }
        if (isset($data['date_to'])) {
            $query->where('created_at', '<', Carbon::parse($data['date_to'])->addDay()->startOfDay());
        }

        return ApiResponse::paginated(PaymentResource::collection($query->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))->withQueryString()));
    }

    public function show(Payment $payment): JsonResponse
    {
        return ApiResponse::success(data: new PaymentResource($payment->load('order')));
    }

    public function transactions(ListPaymentsRequest $request, Payment $payment): JsonResponse
    {
        return ApiResponse::paginated(PaymentTransactionResource::collection($payment->transactions()->paginate($request->integer('per_page', 50))));
    }

    public function collect(Request $request, Order $order, CollectCashPaymentAction $collect): JsonResponse
    {
        return ApiResponse::success(data: new PaymentResource($collect->handle($order, $request->user())));
    }
}
