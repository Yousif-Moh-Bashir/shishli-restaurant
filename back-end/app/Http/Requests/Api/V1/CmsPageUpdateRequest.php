<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PageStatus;
use App\Rules\PlainText;
use App\Rules\SafeContentUrl;
use Illuminate\Validation\Rule;

class CmsPageUpdateRequest extends CmsRequest
{
    public function rules(): array
    {
        return ['title' => ['sometimes', 'required', 'string', 'max:255', new PlainText],
            'slug' => ['sometimes', 'required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('pages', 'slug')->ignore($this->route('page'))],
            'status' => ['sometimes', 'required', Rule::enum(PageStatus::class)], 'published_at' => ['sometimes', 'nullable', 'date'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:100', new PlainText], 'meta_description' => ['sometimes', 'nullable', 'string', 'max:320', new PlainText],
            'canonical_url' => ['sometimes', 'nullable', 'string', 'max:2048', new SafeContentUrl(true)], 'og_image' => $this->imageRules()];
    }
}
