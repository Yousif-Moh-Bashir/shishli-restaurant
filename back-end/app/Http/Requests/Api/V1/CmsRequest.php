<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class CmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $known = array_map(fn (string $key): string => explode('.', $key)[0], array_keys($this->rules()));
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, $known, true) && $key !== '_method') {
                    $validator->errors()->add($key, 'Unknown or immutable field.');
                }
            }
        }];
    }

    protected function imageRules(bool $required = false): array
    {
        return [$required ? 'required' : 'sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
    }
}
