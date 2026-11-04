<?php

namespace Database\Seeders;

use App\Models\Finance\ExpenseCategory;
use App\Models\Finance\RevenueSource;
use App\Services\Finance\FinancePermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FinanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (FinancePermissions::roles() as $name => $permissions) {
                foreach ($permissions as $permission) {
                    Permission::findOrCreate($permission, 'web');
                }
                $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                if ($role->wasRecentlyCreated) {
                    $role->syncPermissions($permissions);
                }
            }
            foreach (['network' => 'شبكة', 'cash' => 'كاش', 'transfer' => 'تحويل', 'keeta' => 'كيتا', 'jahez' => 'جاهز', 'hungerstation' => 'هنقرستيشن'] as $code => $name) {
                RevenueSource::unguarded(fn () => RevenueSource::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]));
            }
            foreach (['rent' => 'الإيجار', 'electricity' => 'الكهرباء', 'salaries' => 'الرواتب', 'maintenance' => 'الصيانة', 'operating' => 'المصاريف التشغيلية'] as $code => $name) {
                ExpenseCategory::unguarded(fn () => ExpenseCategory::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
