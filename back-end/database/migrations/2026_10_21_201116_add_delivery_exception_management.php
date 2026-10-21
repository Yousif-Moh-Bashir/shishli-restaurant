<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 30');
        }
        foreach (['delivery_revision', 'delivery_task_closed_at', 'delivery_exception_requested_at', 'delivery_exception_reason'] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'delivery_revision' => $table->unsignedInteger($column)->default(0),
                        'delivery_exception_reason' => $table->string($column, 1000)->nullable(),
                        default => $table->timestamp($column)->nullable(),
                    };
                });
            }
        }
        if (! Schema::hasIndex('orders', 'orders_delivery_exception_index')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->index(['type', 'delivery_exception_requested_at'], 'orders_delivery_exception_index');
            });
        }
        if (! Schema::hasTable('delivery_operations')) {
            Schema::create('delivery_operations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->uuid('operation_key')->unique();
                $table->string('fingerprint', 64);
                $table->string('action', 40);
                $table->timestamp('created_at');
            });
        }
        if (Schema::getColumnType('admin_activity_logs', 'description') !== 'text') {
            Schema::table('admin_activity_logs', function (Blueprint $table): void {
                $table->text('description')->nullable()->change();
            });
        }
        foreach (['view', 'fail', 'return', 'release', 'cancel', 'resolve_custody'] as $ability) {
            $permission = Permission::findOrCreate('delivery_exceptions.'.$ability, 'web');
            foreach (Role::whereIn('name', ['super_admin', 'manager'])->where('guard_name', 'web')->get() as $role) {
                if ($role->name === 'super_admin' || (! $role->permissions_customized && $ability !== 'resolve_custody')) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('delivery_operations')->exists()) {
            throw new LogicException('لا يمكن إزالة سجل إجراءات توصيل مستخدم. استخدم ترحيلًا تصحيحيًا يحفظ التاريخ.');
        }
        Schema::dropIfExists('delivery_operations');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_delivery_exception_index');
            $table->dropColumn(['delivery_revision', 'delivery_task_closed_at', 'delivery_exception_requested_at', 'delivery_exception_reason']);
        });
    }
};
