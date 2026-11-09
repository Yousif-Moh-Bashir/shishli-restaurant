<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Admin\AdminAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StaffCustomerRequest extends StaffRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('staff.create') ?? false) && ($this->user()?->can('staff.update') ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->routeIs('api.v1.admin.staff.customer_lookup')) {
            return ['phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D']];
        }

        return [
            'customer_uuid' => ['required', 'uuid'],
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(AdminAccessService::STAFF_ROLES)],
            'password' => ['required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()],
            'confirmed' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = $this->routeIs('api.v1.admin.staff.customer_lookup') ? ['phone'] : ['customer_uuid', 'roles', 'password', 'confirmed'];
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add($key, 'هذا الحقل غير مسموح في ربط حساب العميل بالإدارة.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'phone.required' => 'أدخل رقم جوال العميل المسجل.',
            'phone.regex' => 'أدخل رقم جوال صحيحًا مع رمز الدولة.',
            'confirmed.accepted' => 'يجب تأكيد هوية صاحب الحساب قبل منحه صلاحيات الإدارة.',
            'roles.required' => 'اختر دورًا إداريًا واحدًا على الأقل.',
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'customer_uuid' => 'حساب العميل', 'confirmed' => 'تأكيد هوية صاحب الحساب'];
    }
}
