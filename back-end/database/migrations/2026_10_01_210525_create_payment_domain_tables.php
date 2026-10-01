<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('method', 30);
            $table->string('provider', 50)->nullable();
            $table->string('status', 30);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'idempotency_key']);
            $table->unique(['provider', 'provider_payment_id']);
            $table->unique(['provider', 'provider_reference']);
            $table->index(['status', 'created_at']);
            $table->index(['method', 'created_at']);
        });
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('status', 30);
            $table->decimal('amount', 12, 2);
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('request_reference')->nullable();
            $table->string('operation_key', 64)->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'operation_key']);
            $table->unique(['payment_id', 'provider_transaction_id']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider', 50);
            $table->string('provider_event_id')->nullable();
            $table->string('fingerprint', 64);
            $table->string('event_type')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('status', 30);
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'provider_event_id']);
            $table->unique(['provider', 'fingerprint']);
            $table->index(['status', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payments');
    }
};
