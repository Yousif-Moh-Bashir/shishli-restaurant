<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_override', 10, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
            $table->index(['branch_id', 'is_available', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_products');
    }
};
