<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 180);
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('application_type');
            $table->string('discount_type');
            $table->decimal('discount_value', 12, 2);
            $table->decimal('maximum_discount', 12, 2)->nullable();
            $table->decimal('minimum_subtotal', 12, 2)->default(0);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0)->index();
            $table->boolean('is_stackable')->default(false);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'type', 'application_type']);
        });
        foreach (['branches' => 'branch', 'products' => 'product', 'categories' => 'category'] as $targets => $target) {
            Schema::create('promotion_'.$targets, function (Blueprint $table) use ($targets, $target): void {
                $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
                $table->foreignId($target.'_id')->constrained($targets)->cascadeOnDelete();
                $table->unique(['promotion_id', $target.'_id']);
            });
        }
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['promotion_id', 'is_active']);
        });
        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('discount_total', 12, 2)->default(0);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('line_total_after_discount', 12, 2)->default(0);
        });
        DB::table('order_items')->update(['line_total_after_discount' => DB::raw('line_total')]);
        Schema::create('promotion_usages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('used_at')->index();
            $table->timestamps();
            $table->unique(['order_id', 'promotion_id']);
            $table->index(['promotion_id', 'user_id']);
            $table->index(['coupon_id', 'user_id']);
        });
        Schema::create('order_promotions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->uuid('promotion_uuid');
            $table->string('promotion_name', 180);
            $table->string('promotion_type');
            $table->string('discount_type');
            $table->decimal('discount_value', 12, 2);
            $table->string('coupon_code', 64)->nullable();
            $table->decimal('discount_amount', 12, 2);
            $table->timestamps();
            $table->unique(['order_id', 'promotion_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_promotions');
        Schema::dropIfExists('promotion_usages');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['discount_total', 'line_total_after_discount']);
        });
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount_total');
        });
        Schema::dropIfExists('coupons');
        foreach (['branches', 'products', 'categories'] as $target) {
            Schema::dropIfExists('promotion_'.$target);
        }
        Schema::dropIfExists('promotions');
    }
};
