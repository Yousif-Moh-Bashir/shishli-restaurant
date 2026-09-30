<?php

namespace App\Services;

use App\Enums\DeliveryZoneType;
use App\Models\Branch;
use App\Models\DeliveryZone;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryZoneWriter
{
    public static function rules(bool $partial = false): array
    {
        $money = ['numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'];
        $rules = [
            'name' => ['required', 'string', 'max:150'], 'type' => ['required', Rule::enum(DeliveryZoneType::class)],
            'is_active' => ['sometimes', 'boolean'], 'delivery_fee' => array_merge(['required'], $money),
            'minimum_order' => array_merge(['sometimes'], $money),
            'free_delivery_threshold' => array_merge(['nullable'], $money),
            'estimated_min_minutes' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'estimated_max_minutes' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'center_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'center_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'districts' => ['sometimes', 'array', 'max:200'], 'districts.*' => ['required', 'string', 'max:100'],
        ];

        return $partial ? array_map(fn (array $rules): array => array_merge(['sometimes'], $rules), $rules) : $rules;
    }

    public static function messages(): array
    {
        return ['required' => 'الحقل :attribute مطلوب.', 'numeric' => 'الحقل :attribute يجب أن يكون رقمًا.',
            'min' => 'الحقل :attribute أقل من الحد المسموح.', 'max' => 'الحقل :attribute يتجاوز الحد المسموح.',
            'regex' => 'القيمة :attribute يجب أن تكون رقمًا عشريًا بمنزلتين كحد أقصى.',
            'between' => 'الإحداثيات خارج النطاق المسموح.', 'enum' => 'نوع منطقة التوصيل غير صحيح.',
            'array' => 'قائمة الأحياء غير صحيحة.', 'integer' => 'الحقل :attribute يجب أن يكون عددًا صحيحًا.',
            'boolean' => 'قيمة :attribute غير صحيحة.', 'string' => 'الحقل :attribute يجب أن يكون نصًا.'];
    }

    public function save(Branch $branch, array $data, ?string $uuid = null): DeliveryZone
    {
        return DB::transaction(function () use ($branch, $data, $uuid): DeliveryZone {
            $zone = $uuid === null ? new DeliveryZone : $branch->deliveryZones()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $current = $zone->exists ? Arr::only($zone->getAttributes(), array_keys(self::rules())) : [];
            $current['districts'] = $zone->exists ? $zone->districts()->pluck('district_name')->all() : [];
            $final = array_replace(['minimum_order' => '0.00', 'is_active' => true, 'priority' => 0], $current, $data);
            $final = Validator::make($final, self::rules(), self::messages())->validate();
            if ($final['type'] === DeliveryZoneType::District->value) {
                if (empty($final['districts'])) {
                    throw ValidationException::withMessages(['districts' => 'يجب تحديد حي واحد على الأقل.']);
                }
                $names = array_map([AddressNormalizer::class, 'normalize'], $final['districts']);
                if (in_array('', $names, true) || count($names) !== count(array_unique($names))) {
                    throw ValidationException::withMessages(['districts' => 'أسماء الأحياء فارغة أو مكررة بعد توحيد المسافات.']);
                }
                $final['center_latitude'] = $final['center_longitude'] = $final['radius_km'] = null;
            } else {
                foreach (['center_latitude', 'center_longitude', 'radius_km'] as $field) {
                    if (! isset($final[$field])) {
                        throw ValidationException::withMessages([$field => 'إعدادات مركز ونطاق التوصيل مطلوبة.']);
                    }
                }
                $final['districts'] = [];
            }
            foreach (['delivery_fee', 'minimum_order', 'free_delivery_threshold'] as $field) {
                if (isset($final[$field])) {
                    $final[$field] = Money::decimal(Money::minor((string) $final[$field]));
                }
            }
            if (isset($final['free_delivery_threshold']) && Money::minor($final['free_delivery_threshold']) < Money::minor($final['minimum_order'])) {
                throw ValidationException::withMessages(['free_delivery_threshold' => 'حد التوصيل المجاني يجب ألا يقل عن الحد الأدنى للطلب.']);
            }
            if (isset($final['estimated_min_minutes'], $final['estimated_max_minutes']) && $final['estimated_max_minutes'] < $final['estimated_min_minutes']) {
                throw ValidationException::withMessages(['estimated_max_minutes' => 'المدة القصوى يجب ألا تقل عن المدة الدنيا.']);
            }
            $zone->fill(Arr::except($final, 'districts'));
            $zone->branch()->associate($branch);
            $zone->save();
            $zone->districts()->delete();
            $zone->districts()->createMany(array_map(fn (string $name): array => ['district_name' => $name], $final['districts']));

            return $zone->refresh()->load('districts');
        }, 3);
    }
}
