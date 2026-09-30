<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Database\Seeder;

class BranchProductSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('slug', 'al-masif')->first();
        if ($branch === null) {
            return;
        }
        $products = Product::whereIn('slug', [
            'shish-tawook', 'turkish-chicken-with-rice', 'mixed-grill-platter', 'beef-kebab', 'half-turkish-chicken',
        ])->get();
        foreach ($products as $product) {
            BranchProduct::firstOrCreate(['branch_id' => $branch->id, 'product_id' => $product->id], [
                'is_available' => true, 'price_override' => $product->slug === 'shish-tawook' ? '22.00' : null,
            ]);
        }
    }
}
