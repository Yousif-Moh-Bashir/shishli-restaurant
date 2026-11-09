<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Notifications\StaffOrderNotifications;
use App\Services\Notifications\StaffPushEndpoints;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreStaffPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(StaffOrderNotifications::class)->eligible($this->user());
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! app(StaffPushEndpoints::class)->valid($value)) {
                    $fail('عنوان خدمة الإشعارات غير مدعوم.');
                }
            }],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'size:87', 'regex:/^B[A-Za-z0-9_-]{86}$/D'],
            'keys.auth' => ['required', 'string', 'size:22', 'regex:/^[A-Za-z0-9_-]{22}$/D'],
        ];
    }
}
