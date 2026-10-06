<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminRoleMysqlValidationTest extends TestCase
{
    public function test_permission_names_require_exact_case_on_mysql(): void
    {
        if (getenv('INVENTORY_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Enable the existing MySQL suite to verify permission name validation.');
        }

        $database = 'shishli_role_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/^shishli_role_test_[a-f0-9]{16}$/D', $database);
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = $database;
        $server = new \PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $original = DB::getDefaultConnection();

        try {
            config(['database.connections.role_validation' => $connection]);
            DB::setDefaultConnection('role_validation');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'role_validation', '--force' => true, '--no-interaction' => true]));
            $this->seed(RolePermissionSeeder::class);
            $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum');
            $this->putJson('/api/v1/admin/roles/kitchen/permissions', ['permissions' => ['ORDERS.VIEW']])
                ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
            $this->assertDatabaseCount('admin_activity_logs', 0);
            $this->putJson('/api/v1/admin/roles/kitchen/permissions', ['permissions' => ['orders.view']])
                ->assertOk()->assertJsonPath('data.permissions', ['orders.view']);
            $this->assertDatabaseCount('admin_activity_logs', 1);
        } finally {
            DB::purge('role_validation');
            DB::setDefaultConnection($original);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }
}
