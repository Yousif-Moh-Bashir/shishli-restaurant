<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\InitiatePaymentAction;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InitiatePaymentRequest;
use App\Http\Requests\Api\V1\ListPaymentsRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

class OrderPaymentController extends Controller
{
    public function index(ListPaymentsRequest $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return ApiResponse::paginated(PaymentResource::collection($order->payments()->reorder()->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))));
    }

    public function store(InitiatePaymentRequest $request, Order $order, InitiatePaymentAction $initiate): JsonResponse
    {
        return $this->response($initiate->handle($order, $request->user(), $request->validated('idempotency_key')));
    }

    public function guest(InitiatePaymentRequest $request, Order $order, InitiatePaymentAction $initiate): JsonResponse
    {
        return $this->response($initiate->handle($order, null, $request->validated('idempotency_key'), $request->header('X-Order-Token')));
    }

    private function response(Payment $payment): JsonResponse
    {
        return ApiResponse::success(data: ['payment' => new PaymentResource($payment),
            'checkout_url' => in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)
                ? $payment->checkout_url : null]);
    }
}
