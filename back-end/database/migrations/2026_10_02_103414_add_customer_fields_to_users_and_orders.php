<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('phone_normalized', 16)->nullable()->unique();
            $table->string('email')->nullable()->change();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_phone_normalized', 16)->nullable();
            $table->index(['customer_phone_normalized', 'user_id', 'placed_at'], 'orders_guest_phone_history_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_guest_phone_history_index');
            $table->dropColumn('customer_phone_normalized');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone_normalized']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['phone_verified_at', 'is_active', 'phone_normalized']);
        });
        // Nullable emails are preserved: an upgrade may have legitimately cleared one.
    }
};
