<?php

namespace App\Services;

use App\Enums\SettingType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Registry metadata is authoritative; database metadata cannot widen public visibility or validation. */
final class RestaurantSettingDefinition
{
    public function all(): array
    {
        $string = SettingType::String;
        $boolean = SettingType::Boolean;
        $integer = SettingType::Integer;
        $decimal = SettingType::Decimal;
        $define = fn (SettingType $type, mixed $default, array $rules, string $group, bool $public = true): array => ['type' => $type, 'default' => $default, 'rules' => $rules, 'group' => $group, 'is_public' => $public];

        return [
            'restaurant.name' => $define($string, 'مطعم شيشلي للدجاج التركي', ['required', 'string', 'max:150'], 'general'),
            'restaurant.name_en' => $define($string, null, ['nullable', 'string', 'max:150'], 'general'),
            'restaurant.phone' => $define($string, null, ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9 ()-]+$/D'], 'contact'),
            'restaurant.whatsapp' => $define($string, null, ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9 ()-]+$/D'], 'contact'),
            'restaurant.email' => $define($string, null, ['nullable', 'email', 'max:255'], 'contact'),
            // Currency changes require a separate pricing/payment migration; changing a label is insufficient.
            'restaurant.currency' => $define($string, 'SAR', ['required', 'in:SAR'], 'general'),
            'restaurant.timezone' => $define($string, config('business.timezone', 'Asia/Riyadh'), ['required', 'timezone:all'], 'general'),
            'restaurant.country' => $define($string, null, ['nullable', 'string', 'regex:/^[A-Z]{2}$/D'], 'general'),
            'restaurant.city' => $define($string, null, ['nullable', 'string', 'max:150'], 'general'),
            'branding.logo_path' => $define($string, null, ['nullable', 'string', 'max:500', 'regex:/^[a-zA-Z0-9_\\/.-]+$/D', 'not_regex:/\\.\\./'], 'branding'),
            'branding.primary_color' => $define($string, null, ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'], 'branding'),
            'orders.enabled' => $define($boolean, true, ['required', 'boolean'], 'orders'),
            'orders.minimum_amount' => $define($decimal, '0.00', ['required', 'regex:/^[0-9]{1,8}(\\.[0-9]{1,2})?$/D'], 'orders'),
            'orders.maximum_items_per_order' => $define($integer, 1000, ['required', 'integer', 'min:1', 'max:10000'], 'orders'),
            'orders.allow_guest_checkout' => $define($boolean, true, ['required', 'boolean'], 'orders'),
            'orders.guest_tracking_token_days' => $define($integer, 30, ['required', 'integer', 'min:1', 'max:365'], 'orders', false),
            'orders.allow_scheduled_orders' => $define($boolean, false, ['required', 'boolean'], 'orders'),
            'orders.max_scheduling_days' => $define($integer, 7, ['required', 'integer', 'min:1', 'max:366'], 'orders'),
            'orders.maintenance_mode' => $define($boolean, false, ['required', 'boolean'], 'orders'),
            'orders.maintenance_message' => $define($string, 'الطلب غير متاح مؤقتًا، يرجى المحاولة لاحقًا.', ['required', 'string', 'max:500'], 'orders', false),
            'pickup.enabled' => $define($boolean, true, ['required', 'boolean'], 'pickup'),
            'pickup.minimum_amount' => $define($decimal, '0.00', ['required', 'regex:/^[0-9]{1,8}(\\.[0-9]{1,2})?$/D'], 'pickup'),
            'pickup.default_preparation_minutes' => $define($integer, 25, ['required', 'integer', 'min:1', 'max:240'], 'pickup'),
            'delivery.enabled' => $define($boolean, true, ['required', 'boolean'], 'delivery'),
            'delivery.default_preparation_minutes' => $define($integer, 40, ['required', 'integer', 'min:1', 'max:240'], 'delivery'),
            'delivery.require_coordinates' => $define($boolean, false, ['required', 'boolean'], 'delivery'),
        ];
    }

    public function get(string $key): array
    {
        return $this->all()[$key] ?? throw ValidationException::withMessages(['settings.'.$key => 'مفتاح إعداد غير معروف.']);
    }

    /** Literal dotted keys must not be interpreted as nested validation paths. */
    public function validate(array $values): array
    {
        $result = [];
        $errors = [];
        $definitions = $this->all();
        foreach ($values as $key => $value) {
            $definition = $definitions[$key] ?? null;
            if ($definition === null) {
                $errors['settings.'.$key] = ['مفتاح إعداد غير معروف.'];

                continue;
            }
            $validator = Validator::make(['value' => $value], ['value' => $definition['rules']]);
            if ($validator->fails()) {
                $errors['settings.'.$key] = $validator->errors()->get('value');

                continue;
            }
            $result[$key] = $this->cast($definition['type'], $value);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    public function cast(SettingType $type, mixed $value): string|int|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            SettingType::String => (string) $value, SettingType::Boolean => (bool) $value,
            SettingType::Integer => (int) $value, SettingType::Decimal => Money::decimal(Money::minor((string) $value)),
        };
    }
}
