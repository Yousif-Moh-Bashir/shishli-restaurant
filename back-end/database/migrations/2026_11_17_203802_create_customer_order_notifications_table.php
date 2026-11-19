<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_order_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_status_history_id')->constrained()->cascadeOnDelete();
            $table->unique('order_status_history_id', 'customer_notifications_history_unique');
            $table->string('type', 40);
            $table->string('status', 30);
            $table->string('title', 160);
            $table->string('body', 500);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->index(['user_id', 'published_at', 'read_at'], 'customer_notifications_unread_index');
            $table->index(['user_id', 'occurred_at', 'id'], 'customer_notifications_feed_index');
            $table->index(['published_at', 'id'], 'customer_notifications_recovery_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_order_notifications') && DB::table('customer_order_notifications')->exists()) {
            throw new LogicException('Customer notification history is populated. Use a forward-fix migration to preserve it.');
        }
        Schema::dropIfExists('customer_order_notifications');
    }
};
