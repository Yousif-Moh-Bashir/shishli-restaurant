<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertLengths(['name' => 180, 'slug' => 200]);

        Schema::table('products', function (Blueprint $table): void {
            $table->string('name', 180)->change();
            $table->string('slug', 200)->change();
            $table->string('sku', 100)->nullable()->change();
            $table->string('short_description', 500)->nullable()->change();
            $table->softDeletes();
            $table->index(['is_active', 'deleted_at', 'sort_order', 'name'], 'products_public_menu_index');
            $table->index(['category_id', 'is_active', 'deleted_at'], 'products_category_visibility_index');
            $table->index(['is_featured', 'is_available'], 'products_menu_flags_index');
        });
    }

    public function down(): void
    {
        $this->assertLengths(['sku' => 64, 'short_description' => 255]);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_public_menu_index');
            $table->dropIndex('products_category_visibility_index');
            $table->dropIndex('products_menu_flags_index');
            $table->dropSoftDeletes();
            $table->string('name', 255)->change();
            $table->string('slug', 255)->change();
            $table->string('sku', 64)->nullable()->change();
            $table->string('short_description', 255)->nullable()->change();
        });
    }

    /** @param array<string, int> $limits */
    private function assertLengths(array $limits): void
    {
        foreach (DB::table('products')->select(['id', ...array_keys($limits)])->lazyById() as $product) {
            foreach ($limits as $field => $length) {
                if (mb_strlen($product->{$field} ?? '') > $length) {
                    throw new RuntimeException("Product {$product->id}: {$field} exceeds {$length} characters. Resolve it before migrating.");
                }
            }
        }
    }
};
