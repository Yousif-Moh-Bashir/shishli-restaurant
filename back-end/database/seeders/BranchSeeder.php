<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::updateOrCreate(['slug' => 'al-masif'], [
            'name' => 'فرع المصيف',
            'phone' => '0551040122',
            'whatsapp' => '0551040122',
            'city' => 'الرياض',
            'district' => 'المصيف',
            'address' => 'المصيف - بجوار إشارة المصيف',
            'is_active' => true,
            'accepts_orders' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'sort_order' => 1,
        ]);
    }
}
