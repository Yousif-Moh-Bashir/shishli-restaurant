<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['group' => ['id' => $this->option_group_uuid, 'name' => $this->option_group_name],
            'value' => ['id' => $this->option_value_uuid, 'name' => $this->option_value_name], 'price_modifier' => $this->price_modifier];
    }
}
