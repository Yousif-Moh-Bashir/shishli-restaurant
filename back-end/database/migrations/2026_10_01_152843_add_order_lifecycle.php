<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            foreach (['confirmed_at', 'preparing_at', 'ready_at', 'out_for_delivery_at', 'completed_at', 'cancelled_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at', 'id'], 'order_history_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['confirmed_at', 'preparing_at', 'ready_at', 'out_for_delivery_at', 'completed_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
