<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DiscountType;
use App\Enums\PromotionApplicationType;
use App\Enums\PromotionType;
use App\Models\Promotion;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $existing = $this->route('promotion');
        $required = $existing instanceof Promotion ? 'sometimes' : 'required';
        $money = ['regex:/^[0-9]{1,10}(\\.[0-9]{1,2})?$/'];

        return [
            'name' => [$required, 'string', 'max:180'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('promotions', 'slug')->ignore($existing instanceof Promotion ? $existing->id : null)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => [$required, Rule::enum(PromotionType::class)],
            'application_type' => [$required, Rule::enum(PromotionApplicationType::class)],
            'discount_type' => [$required, Rule::enum(DiscountType::class)],
            'discount_value' => [$required, ...$money],
            'maximum_discount' => ['sometimes', 'nullable', ...$money],
            'minimum_subtotal' => ['sometimes', ...$money],
            'starts_at' => ['sometimes', 'nullable', 'date'], 'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'], 'is_stackable' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'usage_limit_per_user' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'branches' => ['sometimes', 'array', 'max:500'], 'branches.*' => ['required', 'uuid', 'distinct', Rule::exists('branches', 'uuid')->whereNull('deleted_at')],
            'products' => ['sometimes', 'array', 'max:500'], 'products.*' => ['required', 'uuid', 'distinct', Rule::exists('products', 'uuid')->whereNull('deleted_at')],
            'categories' => ['sometimes', 'array', 'max:500'], 'categories.*' => ['required', 'uuid', 'distinct', Rule::exists('categories', 'uuid')->whereNull('deleted_at')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $model = $this->route('promotion');
            $data = array_replace($model instanceof Promotion ? $model->getAttributes() : [], $this->validated());
            $application = $data['application_type'];
            foreach (['products' => 'product', 'categories' => 'category'] as $relation => $type) {
                $targets = $this->has($relation) ? $this->input($relation) : (($model instanceof Promotion && $model->application_type->value === $application) ? $model->$relation()->pluck('uuid')->all() : []);
                if ($application === $type && $targets === []) {
                    $validator->errors()->add($relation, 'يجب تحديد هدف واحد على الأقل.');
                }
                if ($application !== $type && $this->has($relation) && $targets !== []) {
                    $validator->errors()->add($relation, 'الأهداف لا تتوافق مع نوع التطبيق.');
                }
            }
            $value = (float) $data['discount_value'];
            if (($data['discount_type'] === 'percentage' && ($value <= 0 || $value > 100)) || ($data['discount_type'] === 'fixed' && $value <= 0)) {
                $validator->errors()->add('discount_value', 'قيمة الخصم غير صالحة.');
            }
            if ($application === 'cart' && $data['discount_type'] === 'fixed_price') {
                $validator->errors()->add('discount_type', 'السعر الخاص مخصص للمنتجات والأقسام.');
            }
            if (($data['starts_at'] ?? null) !== null && ($data['ends_at'] ?? null) !== null && Carbon::parse($data['ends_at'])->lte(Carbon::parse($data['starts_at']))) {
                $validator->errors()->add('ends_at', 'يجب أن تكون النهاية بعد البداية.');
            }
            if ($model instanceof Promotion && $data['type'] === 'automatic' && $model->coupons()->exists()) {
                $validator->errors()->add('type', 'احذف الكوبونات قبل تحويل العرض إلى تلقائي.');
            }
        }];
    }
}
