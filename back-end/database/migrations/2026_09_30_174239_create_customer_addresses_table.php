<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('recipient_name', 150);
            $table->string('phone', 20);
            $table->string('city', 100);
            $table->string('district', 100);
            $table->string('street', 200)->nullable();
            foreach (['building_number', 'floor', 'apartment'] as $field) {
                $table->string($field, 50)->nullable();
            }
            $table->string('landmark')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('default_user_id')->nullable()->storedAs('CASE WHEN is_default = 1 AND deleted_at IS NULL THEN user_id ELSE NULL END');
            $table->unique('default_user_id');
            $table->index(['user_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
