<?php

namespace Database\Seeders;

use App\Models\RestaurantSetting;
use App\Services\RestaurantSettingDefinition;
use App\Services\RestaurantSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RestaurantSettingsSeeder extends Seeder
{
    public function run(): void
    {
        Cache::lock(RestaurantSettingsService::LOCK_KEY, 15)->block(5, function (): void {
            DB::transaction(function (): void {
                foreach (app(RestaurantSettingDefinition::class)->all() as $key => $definition) {
                    RestaurantSetting::firstOrCreate(['key' => $key], ['value' => $definition['default'],
                        'type' => $definition['type'], 'group' => $definition['group'], 'is_public' => $definition['is_public']]);
                }
            });
            app(RestaurantSettingsService::class)->forget();
            DB::afterCommit(fn () => app(RestaurantSettingsService::class)->forget());
        });
    }
}
