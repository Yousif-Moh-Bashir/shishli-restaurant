<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('driver_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->boolean('driver_enabled')->default(false);
            $table->boolean('driver_available')->default(false);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('driver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('delivery_status', 30)->nullable();
            foreach (['driver_accepted_at', 'delivery_picked_up_at', 'delivery_on_the_way_at', 'delivery_delivered_at', 'delivery_failed_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->index(['driver_id', 'delivery_status'], 'orders_driver_active_index');
            $table->index(['branch_id', 'type', 'driver_id', 'status'], 'orders_driver_queue_index');
        });
        Schema::create('order_delivery_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_driver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
        Schema::create('driver_cash_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('reason', 1000);
            $table->timestamps();
        });
        Schema::create('driver_cash_collections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('SAR');
            $table->foreignId('settlement_id')->nullable()->constrained('driver_cash_settlements')->restrictOnDelete();
            $table->timestamp('collected_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['driver_id', 'settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_cash_collections');
        Schema::dropIfExists('driver_cash_settlements');
        Schema::dropIfExists('order_delivery_histories');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_driver_active_index');
            $table->dropIndex('orders_driver_queue_index');
            $table->dropConstrainedForeignId('driver_id');
            $table->dropColumn(['delivery_status', 'driver_accepted_at', 'delivery_picked_up_at', 'delivery_on_the_way_at', 'delivery_delivered_at', 'delivery_failed_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('driver_branch_id');
            $table->dropColumn(['driver_enabled', 'driver_available']);
        });
    }
};
