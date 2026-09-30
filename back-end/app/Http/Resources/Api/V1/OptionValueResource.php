<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OptionValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptionValue */
class OptionValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'price_modifier' => $this->price_modifier,
            'price' => $this->price_modifier,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
