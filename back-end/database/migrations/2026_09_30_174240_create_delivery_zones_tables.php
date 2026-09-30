<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->decimal('delivery_fee', 10, 2);
            $table->decimal('minimum_order', 10, 2)->default(0);
            $table->decimal('free_delivery_threshold', 10, 2)->nullable();
            $table->unsignedInteger('estimated_min_minutes')->nullable();
            $table->unsignedInteger('estimated_max_minutes')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->decimal('center_latitude', 10, 7)->nullable();
            $table->decimal('center_longitude', 10, 7)->nullable();
            $table->decimal('radius_km', 8, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'is_active', 'deleted_at', 'priority'], 'delivery_zones_matching_index');
        });
        Schema::create('delivery_zone_districts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->string('district_name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->unique(['delivery_zone_id', 'normalized_name'], 'delivery_zone_district_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zone_districts');
        Schema::dropIfExists('delivery_zones');
    }
};
