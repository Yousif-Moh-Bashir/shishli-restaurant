<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BannerPlacement;
use App\Rules\PlainText;
use App\Rules\SafeContentUrl;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CmsBannerRequest extends CmsRequest
{
    public function rules(): array
    {
        $create = $this->isMethod('post');

        return ['placement' => [$create ? 'required' : 'sometimes', Rule::enum(BannerPlacement::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:255', new PlainText], 'subtitle' => ['sometimes', 'nullable', 'string', 'max:1000', new PlainText],
            'image' => $this->imageRules($create), 'mobile_image' => $this->imageRules(),
            'button_text' => ['sometimes', 'nullable', 'string', 'max:100', new PlainText], 'button_url' => ['sometimes', 'nullable', 'string', 'max:2048', new SafeContentUrl],
            'secondary_button_text' => ['sometimes', 'nullable', 'string', 'max:100', new PlainText], 'secondary_button_url' => ['sometimes', 'nullable', 'string', 'max:2048', new SafeContentUrl],
            'starts_at' => ['sometimes', 'nullable', 'date'], 'ends_at' => ['sometimes', 'nullable', 'date'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'], 'is_active' => ['sometimes', 'boolean']];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->has('starts_at') || $validator->errors()->has('ends_at')) {
                return;
            }
            $banner = $this->route('banner');
            $start = $this->exists('starts_at') ? $this->input('starts_at') : $banner?->starts_at;
            $end = $this->exists('ends_at') ? $this->input('ends_at') : $banner?->ends_at;
            if ($start !== null && $end !== null && CarbonImmutable::parse($end)->lessThanOrEqualTo(CarbonImmutable::parse($start))) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }
            if ($this->exists('image') && ! $this->hasFile('image')) {
                $validator->errors()->add('image', 'The desktop image cannot be cleared.');
            }
        }];
    }
}
