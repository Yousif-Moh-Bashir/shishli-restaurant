<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeliveryQuoteRequest;
use App\Http\Resources\Api\V1\DeliveryQuoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Services\DeliveryAddressData;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;

class DeliveryQuoteController extends Controller
{
    public function __invoke(DeliveryQuoteRequest $request, DeliveryService $delivery): JsonResponse
    {
        $data = $request->validated();
        $branch = Branch::where('uuid', $data['branch_uuid'])->firstOrFail();

        return ApiResponse::success(data: new DeliveryQuoteResource($delivery->calculateDelivery($branch, DeliveryAddressData::fromArray($data['address']), (string) $data['subtotal'])));
    }
}
