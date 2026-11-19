<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->string('mode', 20)->default('preview');
            $table->unsignedBigInteger('activation_history_id');
            $table->timestamp('activated_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('acceptance_id')->constrained('order_status_histories')->restrictOnDelete();
            $table->foreignId('print_agent_id')->constrained()->restrictOnDelete();
            $table->string('destination', 20);
            $table->uuid('copy_key');
            $table->string('status', 30)->default('pending');
            $table->longText('receipt');
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reprint_reason', 250)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
            $table->unique(['acceptance_id', 'destination', 'copy_key'], 'print_job_copy_unique');
            $table->index(['print_agent_id', 'status', 'available_at'], 'print_job_claim_index');
        });
        Schema::create('print_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('print_job_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 30);
            $table->string('error_code', 50)->nullable();
            $table->string('spooler_job_id', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['print_job_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_job_attempts');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_agents');
    }
};
