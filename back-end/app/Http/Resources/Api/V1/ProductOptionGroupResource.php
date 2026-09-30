<?php

namespace App\Http\Resources\Api\V1;

use App\Services\OptionConfiguration;
use Illuminate\Http\Request;

class ProductOptionGroupResource extends OptionGroupResource
{
    public function toArray(Request $request): array
    {
        $effective = app(OptionConfiguration::class)->effective($this->resource);
        $data = parent::toArray($request);
        $data['is_required'] = $data['required'] = $effective['is_required'];
        $data['min_select'] = $effective['min_select'];
        $data['max_select'] = $effective['max_select'];
        if ($this->resource->relationLoaded('pivot')) {
            $data['sort_order'] = (int) $this->pivot->sort_order;
            if ($request->is('api/v1/admin/*')) {
                $data['overrides'] = [
                    'is_required_override' => $this->pivot->is_required_override === null ? null : (bool) $this->pivot->is_required_override,
                    'min_select_override' => $this->pivot->min_select_override === null ? null : (int) $this->pivot->min_select_override,
                    'max_select_override' => $this->pivot->max_select_override === null ? null : (int) $this->pivot->max_select_override,
                ];
            }
        }

        return $data;
    }
}
