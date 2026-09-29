<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::firstOrCreate(['slug' => 'sample-products'], [
            'name' => 'منتجات تجريبية',
            'is_active' => false,
        ]);

        Product::firstOrCreate(['slug' => 'sample-product'], [
            'category_id' => $category->id,
            'name' => 'منتج تجريبي',
            'base_price' => '25.00',
            'is_active' => false,
            'is_available' => false,
        ]);
    }
}
