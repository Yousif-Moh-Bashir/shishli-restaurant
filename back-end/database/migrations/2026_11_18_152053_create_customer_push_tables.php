<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('subscription');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('consented_at');
            $table->timestamps();
        });
        Schema::create('customer_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('customer_order_notifications')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('customer_push_subscriptions')->nullOnDelete();
            $table->uuid('device_uuid');
            $table->unsignedInteger('subscription_revision');
            $table->timestamp('activated_at');
            $table->timestamp('expires_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'device_uuid'], 'customer_push_event_device_unique');
            $table->index(['status', 'available_at', 'id'], 'customer_push_recovery_index');
        });
    }

    public function down(): void
    {
        if (DB::table('customer_push_deliveries')->exists() || DB::table('customer_push_subscriptions')->exists()) {
            throw new RuntimeException('Refusing to discard customer push history or consent records.');
        }
        Schema::dropIfExists('customer_push_deliveries');
        Schema::dropIfExists('customer_push_subscriptions');
    }
};
