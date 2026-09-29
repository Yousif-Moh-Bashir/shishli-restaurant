<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUserAction $registerUser): JsonResponse
    {
        $user = $registerUser->handle($request->validated());

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'User registered successfully.',
            statusCode: JsonResponse::HTTP_CREATED,
        );
    }
}
