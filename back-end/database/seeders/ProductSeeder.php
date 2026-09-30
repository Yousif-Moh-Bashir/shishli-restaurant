<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        $products = [
            ['shish-tawook', 'شيش طاووق', 'shish', '20.00'],
            ['turkish-chicken-with-rice', 'دجاج تركي مع أرز', 'turkish-chicken', '25.00'],
            ['mixed-grill-platter', 'مشكل مشويات', 'mixed-grills', '45.00'],
            ['beef-kebab', 'كباب لحم', 'meat', '25.00'],
            ['half-turkish-chicken', 'نصف دجاج تركي', 'turkish-chicken', '17.00'],
        ];
        $categories = Category::whereIn('slug', array_column($products, 2))->get()->keyBy('slug');

        foreach ($products as $index => [$slug, $name, $categorySlug, $price]) {
            $category = $categories->get($categorySlug);

            if ($category === null) {
                continue;
            }

            Product::withTrashed()->firstOrCreate(['slug' => $slug], [
                'category_id' => $category->getKey(),
                'name' => $name,
                'base_price' => $price,
                'is_active' => true,
                'is_available' => true,
                'is_featured' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
