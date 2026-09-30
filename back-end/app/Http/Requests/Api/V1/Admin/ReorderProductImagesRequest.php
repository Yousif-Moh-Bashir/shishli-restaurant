<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReorderProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:20'],
            'images.*' => ['required', 'array:id,sort_order'],
            'images.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'images.*.sort_order' => ['required', 'integer', 'min:0', 'max:2147483647', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'قائمة الصور مطلوبة.',
            'images.array' => 'الصور يجب أن تكون قائمة.',
            'images.min' => 'يجب تحديد صورة واحدة على الأقل.',
            'images.max' => 'لا يمكن ترتيب أكثر من 20 صورة في الطلب الواحد.',
            'images.*.required' => 'بيانات الصورة مطلوبة.',
            'images.*.array' => 'بيانات الصورة يجب أن تحتوي على المعرف والترتيب فقط.',
            'images.*.id.required' => 'معرف الصورة مطلوب.',
            'images.*.id.uuid' => 'معرف الصورة غير صحيح.',
            'images.*.id.distinct' => 'لا يمكن تكرار معرف الصورة.',
            'images.*.sort_order.required' => 'ترتيب الصورة مطلوب.',
            'images.*.sort_order.integer' => 'ترتيب الصورة يجب أن يكون عددًا صحيحًا.',
            'images.*.sort_order.min' => 'ترتيب الصورة لا يمكن أن يكون أقل من صفر.',
            'images.*.sort_order.max' => 'ترتيب الصورة كبير جدًا.',
            'images.*.sort_order.distinct' => 'لا يمكن تكرار ترتيب الصور في الطلب.',
        ];
    }
}
