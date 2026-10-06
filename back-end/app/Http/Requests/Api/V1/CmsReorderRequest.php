<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class CmsReorderRequest extends CmsRequest
{
    public function rules(): array
    {
        $table = $this->routeIs('api.v1.admin.banners.reorder') ? 'banners' : 'gallery_items';

        return ['items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'array:id,sort_order'],
            'items.*.id' => ['required', 'uuid', 'distinct', Rule::exists($table, 'uuid')->whereNull('deleted_at')], 'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:2147483647']];
    }
}
