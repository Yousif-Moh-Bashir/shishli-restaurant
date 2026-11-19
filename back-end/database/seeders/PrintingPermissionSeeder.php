<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PrintingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        DB::transaction(function (): void {
            $permissions = collect(['printing.view', 'printing.reprint', 'printing.manage_agents'])
                ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

            $roles = Role::where('guard_name', 'web')->where(function ($query): void {
                $query->where('name', 'super_admin')->orWhere(function ($query): void {
                    $query->where('name', 'manager')->where('permissions_customized', false);
                });
            })->get();

            foreach ($roles as $role) {
                $role->givePermissionTo($permissions);
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
