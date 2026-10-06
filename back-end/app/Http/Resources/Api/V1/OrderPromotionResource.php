<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderPromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'promotion_id' => $this->promotion_uuid, 'name' => $this->promotion_name, 'type' => $this->promotion_type->value, 'discount_type' => $this->discount_type->value, 'discount_value' => $this->discount_value, 'coupon_code' => $this->coupon_code, 'discount' => $this->discount_amount];
    }
}
