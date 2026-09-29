<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ApiResponse
{
    public static function paginated(AnonymousResourceCollection $data): JsonResponse
    {
        return $data->additional(['success' => true, 'message' => 'Success', 'errors' => null])->response();
    }

    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = JsonResponse::HTTP_OK,
    ): JsonResponse {
        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $statusCode);
    }

    /**
     * @param  array<string, list<string>|string>|null  $errors
     */
    public static function error(
        string $message = 'حدث خطأ أثناء تنفيذ الطلب',
        ?array $errors = null,
        int $statusCode = JsonResponse::HTTP_BAD_REQUEST,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null || $statusCode === JsonResponse::HTTP_UNPROCESSABLE_ENTITY) {
            $payload['errors'] = (object) ($errors ?? []);
        }

        return new JsonResponse($payload, $statusCode);
    }
}
