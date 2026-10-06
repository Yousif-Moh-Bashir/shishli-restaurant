<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicConfigurationResource;
use App\Http\Responses\ApiResponse;
use App\Services\RestaurantSettingsService;
use Illuminate\Http\JsonResponse;

class PublicConfigurationController extends Controller
{
    public function __invoke(RestaurantSettingsService $settings): JsonResponse
    {
        return ApiResponse::success(data: new PublicConfigurationResource($settings->publicSettings()))->header('Cache-Control', 'private, no-store');
    }
}
