<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', Rule::enum(NotificationStatus::class)], 'channel' => ['sometimes', Rule::enum(NotificationChannel::class)],
            'type' => ['sometimes', Rule::enum(NotificationType::class)], 'provider' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/D'],
            'recipient' => ['sometimes', 'string', 'max:254'], 'reference_uuid' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:150'], 'page' => ['sometimes', 'integer', 'min:1'], 'sort' => ['sometimes', Rule::in(['created_at', 'status', 'channel', 'type'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
