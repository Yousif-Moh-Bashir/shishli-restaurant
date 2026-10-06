<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 50);
            $table->string('channel', 20);
            $table->string('status', 20)->default('pending');
            $table->string('recipient', 254);
            $table->string('recipient_name')->nullable();
            $table->string('subject')->nullable();
            $table->text('content');
            $table->string('provider', 50)->nullable()->index();
            $table->string('provider_message_id')->nullable();
            $table->string('reference_type', 30)->nullable();
            $table->uuid('reference_uuid')->nullable()->index();
            $table->string('deduplication_key', 200)->nullable()->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('last_error_code', 50)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['channel', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });
        Schema::create('notification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('notification_id')->constrained('outbound_notifications')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20);
            $table->string('provider', 50)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
        Schema::dropIfExists('outbound_notifications');
    }
};
