<?php

namespace Tests\Feature;

use App\Actions\Settings\UpdateRestaurantSettingsAction;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\SettingChangeLog;
use App\Models\User;
use App\Services\RestaurantSettingsService;
use Database\Seeders\RestaurantSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RestaurantSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_defaults_are_typed_and_public_configuration_requires_no_authentication(): void
    {
        $service = app(RestaurantSettingsService::class);
        $this->assertSame('مطعم شيشلي للدجاج التركي', $service->getString('restaurant.name'));
        $this->assertTrue($service->getBoolean('orders.enabled'));
        $this->assertFalse($service->getBoolean('orders.allow_scheduled_orders'));
        $this->assertSame(7, $service->getInteger('orders.max_scheduling_days'));
        $this->assertSame('0.00', $service->getDecimal('orders.minimum_amount'));
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.restaurant.currency', 'SAR')
            ->assertJsonPath('data.restaurant.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.orders.enabled', true)->assertJsonMissingPath('data.orders.maintenance_message')
            ->assertJsonMissingPath('data.payments')->assertJsonMissingPath('data.notifications');
    }

    public function test_operational_schema_can_rollback_and_reapply_without_losing_existing_orders(): void
    {
        $order = Order::factory()->create();
        $migration = require database_path('migrations/2026_10_03_002636_create_restaurant_settings_and_operational_schedules.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('orders', 'scheduled_for'));
        $migration->up();
        $this->assertDatabaseHas('orders', ['uuid' => $order->uuid, 'order_timing' => 'asap', 'scheduled_for' => null]);
        $this->assertTrue(Schema::hasTable('branch_closures'));
        $migration->up();
        $this->assertDatabaseHas('orders', ['uuid' => $order->uuid]);
    }

    public function test_database_metadata_and_requested_key_cannot_expose_private_or_unknown_settings(): void
    {
        RestaurantSetting::create(['key' => 'payment.secret', 'value' => 'secret', 'type' => 'string', 'group' => 'general', 'is_public' => true]);
        RestaurantSetting::create(['key' => 'orders.maintenance_message', 'value' => 'internal message', 'type' => 'string', 'group' => 'orders', 'is_public' => true]);
        $this->getJson('/api/v1/settings/public?key=payment.secret')->assertOk()->assertJsonMissingPath('data.payment')->assertDontSee('secret')
            ->assertJsonMissingPath('data.orders.maintenance_message')->assertDontSee('internal message');
    }

    public function test_update_is_typed_invalidates_warmed_cache_and_records_actor_and_old_values(): void
    {
        $actor = $this->admin();
        $service = app(RestaurantSettingsService::class);
        $this->assertTrue($service->getBoolean('orders.enabled'));
        $this->patchJson('/api/v1/admin/settings', ['settings' => ['orders.enabled' => false, 'orders.minimum_amount' => '12.3', 'pickup.default_preparation_minutes' => 30]])->assertOk();
        $this->assertFalse($service->getBoolean('orders.enabled'));
        $this->assertSame('12.30', $service->getDecimal('orders.minimum_amount'));
        $this->assertSame(30, $service->getInteger('pickup.default_preparation_minutes'));
        $this->assertDatabaseHas('restaurant_settings', ['key' => 'orders.enabled', 'updated_by' => $actor->id]);
        $log = SettingChangeLog::where('setting_key', 'orders.enabled')->firstOrFail();
        $this->assertTrue($log->old_value);
        $this->assertFalse($log->new_value);
        $this->assertSame($actor->id, $log->changed_by);
        $this->assertNotNull($log->created_at);
        $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonCount(26, 'data')->assertJsonMissingPath('data.0.id');
    }

    #[DataProvider('invalidSettings')]
    public function test_bad_multi_update_changes_nothing(string $key, mixed $value): void
    {
        $this->admin();
        $this->patchJson('/api/v1/admin/settings', ['settings' => ['orders.enabled' => false, $key => $value]])->assertUnprocessable();
        $this->assertTrue(app(RestaurantSettingsService::class)->getBoolean('orders.enabled'));
        $this->assertDatabaseCount('restaurant_settings', 0);
        $this->assertDatabaseCount('setting_change_logs', 0);
    }

    public static function invalidSettings(): array
    {
        return [['anything_i_want', true], ['restaurant.timezone', 'Fake/Zone'], ['orders.minimum_amount', '-1'],
            ['orders.minimum_amount', '12.345'], ['orders.maximum_items_per_order', 0], ['orders.max_scheduling_days', 367],
            ['pickup.default_preparation_minutes', 241], ['restaurant.email', 'invalid'], ['branding.primary_color', 'red'],
            ['branding.logo_path', '../secret'], ['restaurant.currency', 'USD'], ['pickup.enabled', 'false'],
            ['restaurant.name', null], ['delivery.enabled', ['true']], ['restaurant.country', 'Saudi Arabia']];
    }

    public function test_seeder_is_idempotent_and_preserves_admin_values(): void
    {
        $this->seed(RestaurantSettingsSeeder::class);
        $user = User::factory()->create();
        app(UpdateRestaurantSettingsAction::class)->handle(['restaurant.name' => 'اسم جديد'], $user);
        $this->seed(RestaurantSettingsSeeder::class);
        $this->assertDatabaseCount('restaurant_settings', 26);
        $this->assertSame('اسم جديد', app(RestaurantSettingsService::class)->getString('restaurant.name'));
        $this->assertDatabaseCount('setting_change_logs', 1);
    }

    public function test_settings_cache_reads_database_once_for_multiple_accessors(): void
    {
        User::factory()->create();
        Cache::forget(RestaurantSettingsService::CACHE_KEY);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service = app(RestaurantSettingsService::class);
        for ($i = 0; $i < 10; $i++) {
            $service->getBoolean('orders.enabled');
            $service->getString('restaurant.name');
        }
        $queries = array_filter(DB::getQueryLog(), fn ($row): bool => str_contains($row['query'], 'restaurant_settings'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
    }

    public function test_audit_filters_by_literal_key_actor_and_business_calendar_date_and_is_read_only(): void
    {
        $actor = $this->admin();
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(22, 0));
        $action = app(UpdateRestaurantSettingsAction::class);
        $action->handle(['orders.enabled' => false], $actor);
        $other = User::factory()->create();
        $action->handle(['pickup.enabled' => false], $other);
        $this->getJson('/api/v1/admin/settings/audit?key=orders.enabled&user_uuid='.$actor->uuid.'&date_from=2026-10-03&date_to=2026-10-03&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.changed_by', $actor->uuid)
            ->assertJsonPath('data.0.old_value', true)->assertJsonPath('data.0.new_value', false)->assertJsonMissingPath('data.0.id');
        $this->getJson('/api/v1/admin/settings/audit?date_to=2026-10-02')->assertOk()->assertJsonCount(0, 'data');
        $log = SettingChangeLog::firstOrFail();
        $this->patchJson('/api/v1/admin/settings/audit/'.$log->uuid, ['new_value' => true])->assertNotFound();
        $this->deleteJson('/api/v1/admin/settings/audit/'.$log->uuid)->assertNotFound();
        $this->travelBack();
    }

    public function test_audit_model_rejects_mutation(): void
    {
        app(UpdateRestaurantSettingsAction::class)->handle(['orders.enabled' => false], User::factory()->create());
        $this->expectException(\LogicException::class);
        SettingChangeLog::firstOrFail()->update(['new_value' => true]);
    }

    public function test_failed_database_transaction_rolls_back_settings_and_audit(): void
    {
        $user = User::factory()->create();
        try {
            DB::transaction(function () use ($user): void {
                app(UpdateRestaurantSettingsAction::class)->handle(['orders.enabled' => false], $user);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }
        app(RestaurantSettingsService::class)->forget();
        $this->assertDatabaseCount('restaurant_settings', 0);
        $this->assertDatabaseCount('setting_change_logs', 0);
        $this->assertTrue(app(RestaurantSettingsService::class)->getBoolean('orders.enabled'));
    }

    #[DataProvider('settingsEndpoints')]
    public function test_settings_endpoints_require_their_own_permission(string $method, string $path, array $body, string $permission): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->json($method, $path, $body)->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->json($method, $path, $body)->assertForbidden();
        $user->givePermissionTo($permission);
        $this->actingAs($user->fresh(), 'sanctum')->json($method, $path, $body)->assertOk();
    }

    public static function settingsEndpoints(): array
    {
        return [['GET', '/api/v1/admin/settings', [], 'settings.view'],
            ['PATCH', '/api/v1/admin/settings', ['settings' => ['orders.enabled' => false]], 'settings.update'],
            ['GET', '/api/v1/admin/settings/audit', [], 'settings.audit.view']];
    }

    public function test_manager_reads_settings_and_audit_but_cannot_update(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/settings')->assertOk();
        $this->getJson('/api/v1/admin/settings/audit')->assertOk();
        $this->patchJson('/api/v1/admin/settings', ['settings' => ['orders.enabled' => false]])->assertForbidden();
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->assignRole('super_admin');
        $this->actingAs($user, 'sanctum');

        return $user;
    }
}
