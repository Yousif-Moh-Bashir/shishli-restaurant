<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_progress_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->string('stage', 30);
            $table->string('progress_key', 64)->unique();
            $table->timestamp('last_progress_at');
            $table->timestamp('reminded_at');
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['resolved_at', 'order_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('delivery_progress_alerts')->exists()) {
            throw new RuntimeException('Refusing to discard delivery alert history. Use a reviewed forward migration.');
        }
        Schema::dropIfExists('delivery_progress_alerts');
    }
};
