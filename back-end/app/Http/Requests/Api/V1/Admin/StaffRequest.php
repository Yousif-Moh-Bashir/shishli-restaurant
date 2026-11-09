<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Admin\AdminAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('staffUser');
        $create = $this->isMethod('post');

        return ['name' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:150'],
            'phone' => [$create ? 'required' : 'sometimes', 'required', 'string', 'regex:/^\\+[1-9][0-9]{7,14}$/D', Rule::unique('users', 'phone')->ignore($user), Rule::unique('users', 'phone_normalized')->ignore($user)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()],
            'roles' => [$create ? 'required' : 'sometimes', 'required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(AdminAccessService::STAFF_ROLES)]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, ['name', 'phone', 'email', 'password', 'roles'], true)) {
                    $validator->errors()->add($key, 'هذا الحقل غير مسموح بتعديله في بيانات الموظف.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute مطلوب.',
            'string' => ':attribute يجب أن يكون نصًا صحيحًا.',
            'array' => 'اختر الأدوار من القائمة المتاحة.',
            'email' => 'أدخل بريدًا إلكترونيًا صحيحًا.',
            'uuid' => 'الحساب المختار غير صالح. أعد البحث عنه.',
            'boolean' => 'قيمة :attribute غير صحيحة.',
            'max.string' => ':attribute يجب ألا يتجاوز :max حرفًا.',
            'min.string' => ':attribute يجب ألا يقل عن :min حرفًا.',
            'phone.regex' => 'أدخل رقم جوال صحيحًا مع رمز الدولة.',
            'phone.unique' => 'رقم الجوال مستخدم بالفعل. استخدم خيار إضافة من حساب عميل موجود إذا كان الرقم مسجلًا كعميل.',
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن :min حرفًا.',
            'password.max' => 'كلمة المرور يجب ألا تتجاوز :max حرفًا.',
            'password.mixed' => 'كلمة المرور يجب أن تحتوي على أحرف كبيرة وصغيرة.',
            'password.numbers' => 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل.',
            'min.array' => 'اختر دورًا إداريًا واحدًا على الأقل.',
            'max.array' => 'لا يمكن اختيار أكثر من :max أدوار إدارية.',
            'roles.*.in' => 'اختر دورًا إداريًا من القائمة المتاحة.',
            'roles.*.distinct' => 'لا يمكن اختيار الدور الإداري نفسه أكثر من مرة.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'الاسم', 'phone' => 'رقم الجوال', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور', 'roles' => 'الأدوار الإدارية', 'roles.*' => 'الدور الإداري'];
    }
}
