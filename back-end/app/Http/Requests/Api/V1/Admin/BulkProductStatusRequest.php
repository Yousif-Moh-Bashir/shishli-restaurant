<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkProductStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function field(): string
    {
        return $this->is('*/bulk/availability') ? 'is_available' : 'is_active';
    }

    public function rules(): array
    {
        return ['product_uuids' => ['required', 'array', 'min:1', 'max:100'], 'product_uuids.*' => ['required', 'uuid', 'distinct', Rule::exists('products', 'uuid')->whereNull('deleted_at')], $this->field() => ['required', 'boolean']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['product_uuids', $this->field()]) as $key) {
                $validator->errors()->add($key, 'Unexpected field.');
            }
        }];
    }
}
