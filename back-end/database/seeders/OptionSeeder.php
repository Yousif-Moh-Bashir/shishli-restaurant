<?php

namespace Database\Seeders;

use App\Models\OptionGroup;
use Illuminate\Database\Seeder;

class OptionSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['size', 'الحجم', 'single', true, 1, 1, [
                ['small', 'صغير', '0.00', true], ['medium', 'وسط', '5.00', false], ['large', 'كبير', '10.00', false],
            ]],
            ['rice-type', 'نوع الأرز', 'single', false, 0, 1, [
                ['bukhari', 'بخاري', '0.00', false], ['basmati', 'بشاور', '0.00', false], ['no-rice', 'بدون أرز', '0.00', false],
            ]],
            ['sauce', 'الصوص', 'single', false, 0, 1, [
                ['garlic', 'ثوم', '0.00', false], ['spicy', 'حار', '0.00', false], ['tahini', 'طحينة', '0.00', false],
            ]],
            ['extras', 'إضافات', 'multiple', false, 0, 3, [
                ['extra-sauce', 'صوص إضافي', '1.00', false], ['extra-cheese', 'جبنة إضافية', '2.00', false], ['fries', 'بطاطس', '5.00', false],
            ]],
        ];

        foreach ($groups as $index => [$slug, $name, $type, $required, $minimum, $maximum, $values]) {
            $group = OptionGroup::withTrashed()->firstOrCreate(['slug' => $slug], [
                'name' => $name, 'type' => $type, 'is_required' => $required, 'min_select' => $minimum,
                'max_select' => $maximum, 'sort_order' => $index + 1, 'is_active' => true,
            ]);
            if ($group->trashed()) {
                continue;
            }
            foreach ($values as $order => [$valueSlug, $valueName, $price, $default]) {
                $group->values()->withTrashed()->firstOrCreate(['slug' => $valueSlug], [
                    'name' => $valueName, 'price_modifier' => $price, 'is_default' => $default,
                    'is_active' => true, 'sort_order' => $order + 1,
                ]);
            }
        }
    }
}
