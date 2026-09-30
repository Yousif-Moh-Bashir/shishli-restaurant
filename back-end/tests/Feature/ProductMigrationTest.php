<?php

namespace Tests\Feature;

use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_product_survives_schema_upgrade_and_rollback(): void
    {
        $product = Product::factory()->create(['base_price' => '19.99']);
        $image = ProductImage::factory()->for($product)->create();
        $group = OptionGroup::factory()->create();
        $product->optionGroups()->attach($group);
        $migration = require database_path('migrations/2026_09_29_150008_complete_products_schema.php');
        $migration->down();
        $original = (array) DB::table('products')->find($product->id);

        $migration->up();

        $this->assertSame('19.99', $product->fresh()->base_price);
        $this->assertSame($product->uuid, $product->fresh()->uuid);
        $this->assertNull($product->fresh()->deleted_at);
        $this->assertModelExists($image);
        $this->assertTrue($product->optionGroups()->whereKey($group->id)->exists());
        $migration->down();
        $this->assertSame($original, (array) DB::table('products')->find($product->id));
        $this->assertFalse(Schema::hasColumn('products', 'deleted_at'));
        $this->assertModelExists($image);
        $this->assertTrue($product->optionGroups()->whereKey($group->id)->exists());
        $migration->up();
    }

    #[DataProvider('legacyLengths')]
    public function test_upgrade_refuses_to_truncate_existing_values(string $field, int $length): void
    {
        $product = Product::factory()->create();
        $migration = require database_path('migrations/2026_09_29_150008_complete_products_schema.php');
        $migration->down();
        $value = str_repeat('a', $length);
        DB::table('products')->where('id', $product->id)->update([$field => $value]);

        try {
            $migration->up();
            $this->fail('Migration must refuse to truncate existing product data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($field, $exception->getMessage());
            $this->assertSame($value, DB::table('products')->where('id', $product->id)->value($field));
            $this->assertFalse(Schema::hasColumn('products', 'deleted_at'));
        } finally {
            DB::table('products')->where('id', $product->id)->update([$field => $product->{$field}]);
            $migration->up();
        }
    }

    public static function legacyLengths(): array
    {
        return ['name' => ['name', 181], 'slug' => ['slug', 201]];
    }

    public function test_rollback_refuses_to_truncate_expanded_sku(): void
    {
        $product = Product::factory()->create(['sku' => str_repeat('a', 100)]);
        $migration = require database_path('migrations/2026_09_29_150008_complete_products_schema.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse to truncate the SKU.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('sku', $exception->getMessage());
            $this->assertSame($product->sku, $product->fresh()->sku);
            $this->assertTrue(Schema::hasColumn('products', 'deleted_at'));
        } finally {
            $product->update(['sku' => null]);
        }
    }
}
