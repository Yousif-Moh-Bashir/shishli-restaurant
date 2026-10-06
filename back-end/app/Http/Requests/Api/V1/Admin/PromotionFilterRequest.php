<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\DiscountType;
use App\Enums\PromotionType;
use Illuminate\Validation\Rule;

class PromotionFilterRequest extends AdminListRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['sort' => ['sometimes', Rule::in(['name', 'priority', 'created_at', 'starts_at', 'ends_at', 'used_count'])], 'status' => ['sometimes', Rule::in(['active', 'future', 'expired', 'inactive'])], 'type' => ['sometimes', Rule::enum(PromotionType::class)], 'discount_type' => ['sometimes', Rule::enum(DiscountType::class)], 'branch_uuid' => ['sometimes', 'uuid']]);
    }
}
