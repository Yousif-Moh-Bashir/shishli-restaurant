<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->uuid('category_uuid')->nullable();
            $table->string('category_name')->nullable();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('placed_at', 'orders_report_placement_index');
        });
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->index(['status', 'type', 'processed_at'], 'transactions_collection_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['category_uuid', 'category_name']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_report_placement_index');
        });
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_collection_period_index');
        });
    }
};
