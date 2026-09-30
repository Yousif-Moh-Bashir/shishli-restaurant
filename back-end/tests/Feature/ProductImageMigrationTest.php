<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductImageMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_backfills_uuid_disk_and_one_primary_without_losing_paths(): void
    {
        $product = Product::factory()->create();
        $migration = require database_path('migrations/2026_09_29_170147_complete_product_images_schema.php');
        $migration->down();
        foreach ([2, 1] as $order) {
            DB::table('product_images')->insert([
                'product_id' => $product->id, 'path' => 'products/'.$order.'.jpg',
                'is_primary' => true, 'sort_order' => $order,
            ]);
        }
        $migration->up();
        $images = $product->images()->get();
        $this->assertCount(2, $images);
        $this->assertSame('products/1.jpg', $images[0]->path);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);
        $this->assertSame('public', $images[0]->disk);
        $this->assertTrue(Str::isUuid($images[0]->uuid));
        $this->assertNotSame($images[0]->uuid, $images[1]->uuid);
    }
}
