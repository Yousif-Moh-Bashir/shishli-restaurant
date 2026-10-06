<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\PlainText;
use Illuminate\Validation\Validator;

class CmsGalleryRequest extends CmsRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255', new PlainText], 'description' => ['sometimes', 'nullable', 'string', 'max:5000', new PlainText],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255', new PlainText], 'image' => $this->imageRules($this->isMethod('post')),
            'published_at' => ['sometimes', 'nullable', 'date'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'], 'is_active' => ['sometimes', 'boolean']];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->exists('image') && ! $this->hasFile('image')) {
                $validator->errors()->add('image', 'The image cannot be cleared.');
            }
        }];
    }
}
