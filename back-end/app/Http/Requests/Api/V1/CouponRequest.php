<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PromotionType;
use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normalizeCode($this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return ['code' => [$coupon instanceof Coupon ? 'sometimes' : 'required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'is_active' => ['sometimes', 'boolean'], 'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'usage_limit_per_user' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'starts_at' => ['sometimes', 'nullable', 'date'], 'ends_at' => ['sometimes', 'nullable', 'date']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('promotion')->type !== PromotionType::Coupon) {
                $validator->errors()->add('promotion', 'الكوبونات تتطلب عرضًا من نوع كوبون.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $coupon = $this->route('coupon');
            $data = array_replace($coupon instanceof Coupon ? $coupon->getAttributes() : [], $this->validated());
            if (($data['starts_at'] ?? null) !== null && ($data['ends_at'] ?? null) !== null && Carbon::parse($data['ends_at'])->lte(Carbon::parse($data['starts_at']))) {
                $validator->errors()->add('ends_at', 'يجب أن تكون النهاية بعد البداية.');
            }
        }];
    }
}
