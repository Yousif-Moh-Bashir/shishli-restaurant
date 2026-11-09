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
                $current = $this->settings->all(true);
                $enabled = $values['orders.enabled'] ?? $current['orders.enabled'];
                $maintenance = $values['orders.maintenance_mode'] ?? $current['orders.maintenance_mode'];
                $wasPaused = ! $current['orders.enabled'] || $current['orders.maintenance_mode'];
                if (($enabled && ! $maintenance) || (! $wasPaused && (! $enabled || $maintenance) && ! array_key_exists('orders.expected_resume_at', $values))) {
                    if ($current['orders.expected_resume_at'] !== null || array_key_exists('orders.expected_resume_at', $values)) {
                        $values['orders.expected_resume_at'] = null;
                    }
                }
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
