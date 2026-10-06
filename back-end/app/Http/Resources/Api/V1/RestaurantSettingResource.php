<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['key' => $this->resource['key'], 'value' => $this->resource['value'],
            'type' => $this->resource['type']->value, 'group' => $this->resource['group'],
            'is_public' => $this->resource['is_public'], 'updated_at' => $this->resource['updated_at']];
    }
}
