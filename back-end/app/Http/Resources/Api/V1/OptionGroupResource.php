<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OptionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptionGroup */
class OptionGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type->value,
            'is_required' => $this->is_required,
            'required' => $this->is_required,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'values' => OptionValueResource::collection($this->whenLoaded('values')),
            'options' => OptionValueResource::collection($this->whenLoaded('values')),
        ];
    }
}
