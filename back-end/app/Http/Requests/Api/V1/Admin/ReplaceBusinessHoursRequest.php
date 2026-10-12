<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\DayOfWeek;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('branches.hours.update') ?? false;
    }

    public function rules(): array
    {
        return ['hours' => ['present', 'array', 'max:100'], 'hours.*' => ['array:day_of_week,opens_at,closes_at'],
            'hours.*.day_of_week' => ['required', 'integer', Rule::enum(DayOfWeek::class)],
            'hours.*.opens_at' => ['required', 'date_format:H:i'], 'hours.*.closes_at' => ['required', 'date_format:H:i']];
    }

    public function messages(): array
    {
        return [
            'hours.*.opens_at.required' => 'أدخل وقت الفتح.',
            'hours.*.closes_at.required' => 'أدخل وقت الإغلاق.',
            'hours.*.opens_at.date_format' => 'اختر وقت فتح صحيحًا بالساعات والدقائق.',
            'hours.*.closes_at.date_format' => 'اختر وقت إغلاق صحيحًا بالساعات والدقائق.',
            'hours.*.day_of_week.required' => 'اختر يوم العمل.',
            'hours.*.day_of_week.integer' => 'يوم العمل غير صحيح.',
            'hours.*.day_of_week.enum' => 'يوم العمل غير صحيح.',
            'hours.max' => 'يمكن إضافة 100 فترة عمل كحد أقصى.',
        ];
    }
}
