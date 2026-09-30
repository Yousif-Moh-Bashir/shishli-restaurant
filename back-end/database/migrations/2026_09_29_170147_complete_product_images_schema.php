<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique();
            $table->string('disk')->default('public');
        });

        foreach (DB::table('product_images')->select('id')->lazyById() as $image) {
            DB::table('product_images')->where('id', $image->id)->update(['uuid' => (string) Str::uuid()]);
        }

        foreach (DB::table('products')->select('id')->lazyById() as $product) {
            $images = DB::table('product_images')->where('product_id', $product->id);
            $primary = (clone $images)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->first();

            if ($primary !== null) {
                $images->update(['is_primary' => false]);
                DB::table('product_images')->where('id', $primary->id)->update(['is_primary' => true]);
            }
        }

        Schema::table('product_images', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
            $table->index(['product_id', 'is_primary'], 'product_images_primary_index');
            $table->index(['product_id', 'sort_order', 'id'], 'product_images_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropIndex('product_images_primary_index');
            $table->dropIndex('product_images_order_index');
            $table->dropUnique('product_images_uuid_unique');
            $table->dropColumn(['uuid', 'disk']);
        });
    }
};
