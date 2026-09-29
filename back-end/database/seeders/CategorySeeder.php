<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'grills' => 'المشويات',
            'skewers' => 'الشيش',
            'mixed-platters' => 'أطباق مشكل',
            'meat' => 'اللحوم',
            'sandwiches' => 'السندوتشات',
            'appetizers' => 'المقبلات',
            'stews' => 'الإيدامات',
            'rice' => 'الأرز',
            'juices' => 'العصائر',
            'extras' => 'الإضافات',
        ];

        $sortOrder = 10;

        foreach ($categories as $slug => $name) {
            Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'sort_order' => $sortOrder]);
            $sortOrder += 10;
        }
    }
}
