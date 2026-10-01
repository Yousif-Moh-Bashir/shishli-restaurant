<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\HandlePaymentWebhookAction;
use App\Enums\WebhookEventStatus;
use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, HandlePaymentWebhookAction $handler): JsonResponse
    {
        $event = $handler->handle($provider, $request->getContent(), $request->headers->all());
        if ($event->status === WebhookEventStatus::Received) {
            throw new PaymentException('PROVIDER_PAYMENT_NOT_READY', 503);
        }

        return ApiResponse::success();
    }
}
