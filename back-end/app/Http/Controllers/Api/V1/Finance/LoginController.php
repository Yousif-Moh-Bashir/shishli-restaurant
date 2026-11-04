<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Auth\LoginUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, LoginUserAction $login): JsonResponse
    {
        $result = $login->handle($request->validated(), finance: true);

        return ApiResponse::success([
            'user' => new UserResource($result['user']), 'token' => $result['token'],
            'token_type' => 'Bearer', 'expires_in' => config('finance.token_minutes') * 60,
        ]);
    }
}
