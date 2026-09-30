<?php

namespace Database\Seeders;

use App\Actions\Delivery\CreateDeliveryZoneAction;
use App\Actions\Delivery\UpdateDeliveryZoneAction;
use App\Models\Branch;
use Illuminate\Database\Seeder;

class DeliveryZoneSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('slug', 'al-masif')->first();
        if ($branch === null) {
            return;
        }
        $data = ['name' => 'المصيف والمناطق القريبة', 'type' => 'district', 'is_active' => true, 'districts' => ['المصيف'],
            'delivery_fee' => '5.00', 'minimum_order' => '20.00', 'free_delivery_threshold' => '100.00',
            'estimated_min_minutes' => 30, 'estimated_max_minutes' => 45, 'priority' => 100];
        $zone = $branch->deliveryZones()->where('name', $data['name'])->first();
        if ($zone === null) {
            app(CreateDeliveryZoneAction::class)->handle($branch, $data);
        } else {
            app(UpdateDeliveryZoneAction::class)->handle($branch, $zone->uuid, $data);
        }
    }
}
