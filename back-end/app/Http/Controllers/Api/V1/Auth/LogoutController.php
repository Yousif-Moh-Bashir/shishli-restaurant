<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LogoutUserAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutUserAction $logoutUser): JsonResponse
    {
        $logoutUser->handle($request->user());

        return ApiResponse::success(message: 'تم تسجيل الخروج');
    }
}
