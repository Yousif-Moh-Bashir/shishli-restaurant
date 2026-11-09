<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('staff_order_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->timestamp('activated_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('staff_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('subscription');
            $table->timestamps();
        });
        Schema::create('staff_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('staff_order_notifications')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('destination', 100);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'channel', 'destination'], 'staff_delivery_destination_unique');
            $table->index(['status', 'available_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_notification_deliveries');
        Schema::dropIfExists('staff_push_subscriptions');
        Schema::dropIfExists('staff_order_notifications');
    }
};
