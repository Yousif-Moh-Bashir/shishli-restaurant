<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Finance\AuditLog;
use App\Models\Finance\RevenueSource;
use App\Models\Finance\Supplier;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceSettingsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        if (config('database.default') === 'mysql') {
            $this->assertSame('finance-mysql', config('database.connections.mysql.host'));
            $this->assertSame('shishli_finance_test', config('database.connections.mysql.database'));
            $this->assertEmpty(config('database.connections.mysql.url'));
        } else {
            $this->assertSame('sqlite', config('database.default'));
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        }
    }

    public function test_code_upgrade_preserves_audited_legacy_name_and_uuid_without_recreating_defaults(): void
    {
        $this->seed(FinanceSeeder::class);
        $user = User::factory()->create();
        $source = RevenueSource::where('code', 'network')->firstOrFail();
        $migration = require database_path('migrations/2026_11_02_174242_add_stable_codes_to_finance_settings.php');
        $migration->down();
        DB::table('finance_revenue_sources')->where('uuid', $source->uuid)->update(['name' => 'شبكة أعيدت تسميتها']);
        AuditLog::create([
            'user_id' => $user->id, 'branch_id' => null, 'action' => 'sources.update', 'subject_type' => 'finance_revenue_sources',
            'subject_uuid' => $source->uuid, 'old_values' => ['name' => 'شبكة'], 'new_values' => ['name' => 'شبكة أعيدت تسميتها'],
        ]);
        $migration->up();
        $this->seed(FinanceSeeder::class);
        $this->seed(FinanceSeeder::class);
        $this->assertDatabaseHas('finance_revenue_sources', ['uuid' => $source->uuid, 'code' => 'network', 'name' => 'شبكة أعيدت تسميتها']);
        $this->assertDatabaseCount('finance_revenue_sources', 6);
    }

    public function test_optional_supplier_upgrade_preserves_purchases_and_blocks_lossy_rollback(): void
    {
        $this->seed(FinanceSeeder::class);
        $actor = User::factory()->create()->assignRole('finance_manager');
        $this->withToken($actor->createToken('finance', ['finance'], now()->addHours(8))->plainTextToken);
        $branch = Branch::factory()->create();
        $supplier = Supplier::create(['name' => 'مورد قائم', 'is_active' => true]);
        $data = ['branch_uuid' => $branch->uuid, 'date' => now()->toDateString(), 'amount' => '20.25', 'description' => 'فاتورة قائمة',
            'payment_method' => 'cash', 'supplier_uuid' => $supplier->uuid, 'invoice_number' => 'EXISTING-1'];
        $uuid = $this->postJson('/api/v1/finance/purchases', $data)->assertCreated()->json('data.uuid');
        $migration = require database_path('migrations/2026_11_03_160312_make_finance_purchase_supplier_optional.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('finance_purchases', ['uuid' => $uuid, 'supplier_id' => $supplier->id, 'amount' => '20.25', 'invoice_number' => 'EXISTING-1']);
        $this->postJson('/api/v1/finance/purchases', $data)->assertUnprocessable()->assertJsonValidationErrors('invoice_number');
        $direct = $this->postJson('/api/v1/finance/purchases', [...$data, 'supplier_uuid' => null, 'invoice_number' => 'DIRECT-1'])
            ->assertCreated()->assertJsonPath('data.supplier', null)->json('data.uuid');
        try {
            $migration->down();
            $this->fail('Rollback must refuse direct purchases without suppliers.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot require a supplier', $error->getMessage());
            $this->assertDatabaseHas('finance_purchases', ['uuid' => $direct, 'supplier_id' => null, 'amount' => '20.25']);
        } finally {
            DB::table('finance_purchases')->where('uuid', $direct)->delete();
        }
    }
}
