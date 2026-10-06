<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB DDL does not roll back earlier CREATE statements; preserve completed tables on a retry.
        if (! Schema::hasTable('restaurant_settings')) {
            Schema::create('restaurant_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->string('type', 20);
                $table->string('group', 30);
                $table->boolean('is_public')->default(false);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('setting_change_logs')) {
            Schema::create('setting_change_logs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('setting_key');
                $table->json('old_value')->nullable();
                $table->json('new_value')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at');
                $table->index(['setting_key', 'created_at']);
            });
        }
        if (! Schema::hasTable('branch_business_hours')) {
            Schema::create('branch_business_hours', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('day_of_week');
                $table->time('opens_at');
                $table->time('closes_at');
                $table->timestamps();
                $table->index(['branch_id', 'day_of_week']);
            });
        }
        if (! Schema::hasTable('branch_closures')) {
            Schema::create('branch_closures', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('reason', 1000)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['branch_id', 'is_active', 'starts_at', 'ends_at'], 'branch_closures_period_index');
            });
        }
        if (! Schema::hasColumn('orders', 'order_timing')) {
            Schema::table('orders', fn (Blueprint $table) => $table->string('order_timing', 20)->default('asap'));
        }
        if (! Schema::hasColumn('orders', 'scheduled_for')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dateTime('scheduled_for')->nullable()->index());
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(['scheduled_for']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['order_timing', 'scheduled_for']));
        Schema::dropIfExists('branch_closures');
        Schema::dropIfExists('branch_business_hours');
        Schema::dropIfExists('setting_change_logs');
        Schema::dropIfExists('restaurant_settings');
    }
};
