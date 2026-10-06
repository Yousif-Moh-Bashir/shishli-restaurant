<?php

namespace App\Actions\Settings;

use App\Models\RestaurantSetting;
use App\Models\SettingChangeLog;
use App\Models\User;
use App\Services\RestaurantSettingDefinition;
use App\Services\RestaurantSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UpdateRestaurantSettingsAction
{
    public function __construct(private RestaurantSettingDefinition $definitions, private RestaurantSettingsService $settings) {}

    public function handle(array $values, User $actor): void
    {
        $values = $this->definitions->validate($values);
        Cache::lock(RestaurantSettingsService::LOCK_KEY, 15)->block(5, function () use ($values, $actor): void {
            DB::transaction(function () use ($values, $actor): void {
                ksort($values);
                foreach ($values as $key => $value) {
                    $definition = $this->definitions->get($key);
                    $row = RestaurantSetting::query()->where('key', $key)->lockForUpdate()->first();
                    $old = $row === null ? $definition['default'] : $this->definitions->cast($definition['type'], $row->value);
                    RestaurantSetting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => $definition['type'],
                        'group' => $definition['group'], 'is_public' => $definition['is_public'], 'updated_by' => $actor->id]);
                    if ($old !== $value) {
                        SettingChangeLog::create(['setting_key' => $key, 'old_value' => $old, 'new_value' => $value, 'changed_by' => $actor->id]);
                    }
                }
            }, 3);
            $this->settings->forget();
            DB::afterCommit(fn () => $this->settings->forget());
        });
    }
}
