<?php

namespace App\Services;

use App\Enums\SettingType;
use App\Models\RestaurantSetting;
use Illuminate\Support\Facades\Cache;

class RestaurantSettingsService
{
    public const CACHE_KEY = 'restaurant.settings.v1';

    public const LOCK_KEY = 'restaurant.settings.write';

    public function __construct(private RestaurantSettingDefinition $definitions) {}

    /** A single cached snapshot, without long-lived instance memoization. */
    public function all(bool $fresh = false): array
    {
        $read = fn (): array => RestaurantSetting::query()->pluck('value', 'key')->all();
        $stored = $fresh ? $read() : Cache::lock(self::LOCK_KEY, 15)->block(5, fn (): array => Cache::rememberForever(self::CACHE_KEY, $read));
        $values = [];
        foreach ($this->definitions->all() as $key => $definition) {
            $values[$key] = $this->definitions->cast($definition['type'], array_key_exists($key, $stored) ? $stored[$key] : $definition['default']);
        }

        return $values;
    }

    public function get(string $key): string|int|bool|null
    {
        $this->definitions->get($key);

        return $this->all()[$key];
    }

    public function getString(string $key): ?string
    {
        $this->assertType($key, SettingType::String);

        return $this->get($key);
    }

    public function getBoolean(string $key): bool
    {
        $this->assertType($key, SettingType::Boolean);

        return $this->get($key);
    }

    public function getInteger(string $key): int
    {
        $this->assertType($key, SettingType::Integer);

        return $this->get($key);
    }

    public function getDecimal(string $key): string
    {
        $this->assertType($key, SettingType::Decimal);

        return $this->get($key);
    }

    private function assertType(string $key, SettingType $type): void
    {
        if ($this->definitions->get($key)['type'] !== $type) {
            throw new \InvalidArgumentException('Incorrect typed accessor for '.$key);
        }
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function publicSettings(): array
    {
        $values = $this->all();
        $public = [];
        foreach ($this->definitions->all() as $key => $definition) {
            if ($definition['is_public']) {
                data_set($public, $key, $values[$key]);
            }
        }
        if ($values['orders.maintenance_mode']) {
            $public['orders']['maintenance_message'] = $values['orders.maintenance_message'];
        }

        return $public;
    }
}
