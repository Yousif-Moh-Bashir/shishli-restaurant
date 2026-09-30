<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'turkish-chicken' => 'الدجاج التركي',
            'shish' => 'الشيش',
            'grills' => 'المشويات',
            'mixed-grills' => 'أطباق المشكل',
            'meat' => 'اللحوم',
            'sandwiches' => 'السندوتشات',
            'appetizers' => 'المقبلات',
            'stews' => 'الإيدامات',
            'rice' => 'الأرز',
            'drinks' => 'العصائر والمشروبات',
            'extras' => 'الإضافات',
        ];

        $sortOrder = 1;

        foreach ($categories as $slug => $name) {
            Category::withTrashed()->firstOrCreate(['slug' => $slug], [
                'name' => $name, 'sort_order' => $sortOrder, 'parent_id' => null, 'is_active' => true,
            ]);
            $sortOrder++;
        }
    }
}
