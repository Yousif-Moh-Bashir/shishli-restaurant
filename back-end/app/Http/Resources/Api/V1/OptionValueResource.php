<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OptionValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptionValue */
class OptionValueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price_modifier,
            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,
        ];
    }
}
