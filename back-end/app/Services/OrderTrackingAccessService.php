<?php

namespace App\Services;

use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class OrderTrackingAccessService
{
    public function __construct(private GuestOrderAccess $guests) {}

    public function resolveForAuthenticatedUser(string $uuid, User $user): Order
    {
        abort_unless($user->is_active, 401);

        return Order::query()->without('promotions')->where('uuid', $uuid)->where('user_id', $user->id)->firstOrFail();
    }

    public function resolveForGuest(string $number, #[\SensitiveParameter] string $token): Order
    {
        $order = Order::query()->without('promotions')->where('order_number', $number)->first();
        if (! $this->guests->matches($order, $token)) {
            throw new HttpResponseException(ApiResponse::error('تعذر العثور على الطلب أو بيانات التتبع غير صحيحة.', statusCode: 404)
                ->header('Cache-Control', 'private, no-store'));
        }

        return $order;
    }
}
