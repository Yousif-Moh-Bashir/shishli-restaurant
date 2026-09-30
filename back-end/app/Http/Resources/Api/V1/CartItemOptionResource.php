<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'group' => ['id' => $this->optionGroup->uuid, 'name' => $this->option_group_name],
            'value' => ['id' => $this->optionValue->uuid, 'name' => $this->option_value_name],
            'price_modifier' => $this->price_modifier,
        ];
    }
}
