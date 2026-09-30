<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('active_user_id')->nullable()->storedAs("CASE WHEN status = 'active' THEN user_id ELSE NULL END");
            $table->unique(['active_user_id', 'branch_id']);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('configuration_hash', 64);
            $table->decimal('base_price', 12, 2);
            $table->decimal('options_total', 12, 2)->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
            $table->unique(['cart_id', 'configuration_hash']);
        });
        Schema::create('cart_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('option_value_id')->constrained()->restrictOnDelete();
            $table->string('option_group_name');
            $table->string('option_value_name');
            $table->decimal('price_modifier', 12, 2);
            $table->timestamps();
            $table->unique(['cart_item_id', 'option_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_options');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
