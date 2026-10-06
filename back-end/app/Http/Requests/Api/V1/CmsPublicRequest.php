<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BannerPlacement;
use Illuminate\Validation\Rule;

class CmsPublicRequest extends CmsRequest
{
    public function rules(): array
    {
        return [
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'uuid')->where('is_active', true)->whereNull('deleted_at')],
            'placement' => ['sometimes', Rule::enum(BannerPlacement::class)], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
