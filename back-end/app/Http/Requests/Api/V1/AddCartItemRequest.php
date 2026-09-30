<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($this->input('product_uuid'))) {
            $data['product_uuid'] = strtolower($this->input('product_uuid'));
        }
        $options = $this->input('options');
        if (is_array($options)) {
            foreach ($options as &$option) {
                if (! is_array($option)) {
                    continue;
                }
                if (is_string($option['option_group_uuid'] ?? null)) {
                    $option['option_group_uuid'] = strtolower($option['option_group_uuid']);
                }
                if (is_array($option['option_value_uuids'] ?? null)) {
                    $option['option_value_uuids'] = array_map(fn (mixed $uuid): mixed => is_string($uuid) ? strtolower($uuid) : $uuid, $option['option_value_uuids']);
                }
            }
            unset($option);
            $data['options'] = $options;
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'product_uuid' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'options' => ['sometimes', 'array', 'max:100'],
            'options.*' => ['array:option_group_uuid,option_value_uuids'],
            'options.*.option_group_uuid' => ['required', 'uuid', 'distinct:ignore_case'],
            'options.*.option_value_uuids' => ['present', 'array', 'max:100'],
            'options.*.option_value_uuids.*' => ['required', 'uuid', 'distinct:ignore_case'],
        ];
    }

    public function messages(): array
    {
        return ['required' => ':attribute مطلوب.', 'present' => 'قائمة الخيارات المحددة مطلوبة.',
            'uuid' => 'المعرف غير صحيح.', 'integer' => 'الكمية يجب أن تكون عددًا صحيحًا.',
            'min' => ':attribute يجب ألا يقل عن :min.', 'max' => ':attribute يتجاوز الحد المسموح (:max).',
            'array' => 'صيغة الخيارات غير صحيحة.', 'distinct' => 'لا يمكن تكرار مجموعة أو خيار في الطلب.'];
    }

    public function attributes(): array
    {
        return ['product_uuid' => 'المنتج', 'quantity' => 'الكمية', 'options' => 'الخيارات',
            'options.*.option_group_uuid' => 'مجموعة الخيارات', 'options.*.option_value_uuids' => 'القيم المختارة'];
    }
}
