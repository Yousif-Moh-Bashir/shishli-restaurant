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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cart_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('access_token', 64)->nullable()->unique();
            $table->string('type', 20);
            $table->string('status', 30)->default('pending');
            $table->string('payment_method', 20);
            $table->string('payment_status', 20)->default('pending');
            $table->string('customer_name', 150);
            $table->string('customer_phone', 20);
            $table->string('customer_email')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->default('SAR');
            $table->text('customer_notes')->nullable();
            $table->timestamp('placed_at');
            $table->timestamps();
            $table->index(['user_id', 'placed_at']);
            $table->index(['branch_id', 'placed_at']);
            $table->index(['status', 'placed_at']);
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('product_uuid');
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->unsignedInteger('quantity');
            foreach (['base_price', 'options_total', 'unit_price', 'line_total'] as $column) {
                $table->decimal($column, 12, 2);
            }
            $table->timestamps();
        });
        Schema::create('order_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->uuid('option_group_uuid');
            $table->string('option_group_name');
            $table->uuid('option_value_uuid');
            $table->string('option_value_name');
            $table->decimal('price_modifier', 12, 2);
            $table->timestamps();
        });
        Schema::create('order_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient_name', 150);
            $table->string('phone', 20);
            $table->string('city', 100);
            $table->string('district', 100);
            foreach (['street', 'building_number', 'floor', 'apartment', 'landmark'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('delivery_zone_name')->nullable();
            $table->uuid('delivery_zone_uuid')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
