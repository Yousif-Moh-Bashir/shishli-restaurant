<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'يرجى اختيار صورة واحدة على الأقل.',
            'images.array' => 'الصور يجب أن تكون قائمة ملفات.',
            'images.min' => 'يرجى اختيار صورة واحدة على الأقل.',
            'images.max' => 'يمكن رفع 10 صور كحد أقصى في الطلب الواحد.',
            'images.*.required' => 'ملف الصورة مطلوب.',
            'images.*.image' => 'الملف يجب أن يكون صورة صالحة.',
            'images.*.mimes' => 'الصيغ المسموحة هي JPG وJPEG وPNG وWebP فقط.',
            'images.*.max' => 'حجم كل صورة يجب ألا يتجاوز 5 ميجابايت.',
            'images.*.uploaded' => 'تعذر رفع الصورة.',
            'alt_text.string' => 'النص البديل يجب أن يكون نصًا.',
            'alt_text.max' => 'النص البديل يجب ألا يتجاوز 255 حرفًا.',
            'is_primary.boolean' => 'قيمة الصورة الرئيسية غير صحيحة.',
        ];
    }
}
