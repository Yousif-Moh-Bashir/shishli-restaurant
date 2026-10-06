<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AdminProfileResource;
use App\Http\Responses\ApiResponse;
use App\Services\Admin\AdminBootstrapService;
use App\Services\Admin\AdminNavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSessionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new AdminProfileResource($request->user()));
    }

    public function bootstrap(Request $request, AdminBootstrapService $bootstrap): JsonResponse
    {
        return ApiResponse::success($bootstrap->get($request->user()));
    }

    public function navigation(Request $request, AdminNavigationService $navigation): JsonResponse
    {
        return ApiResponse::success($navigation->get($request->user()));
    }
}
