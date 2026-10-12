<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            RestaurantSettingsSeeder::class,
            CmsPageSeeder::class,
            BranchSeeder::class,
            DeliveryZoneSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            OptionSeeder::class,
            BranchProductSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
