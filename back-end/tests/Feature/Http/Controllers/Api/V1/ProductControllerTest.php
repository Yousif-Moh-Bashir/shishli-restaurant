<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('productStates')]
    public function test_menu_visibility_depends_on_active_flag_and_not_availability(bool $active, bool $available, int $visibleCount): void
    {
        $product = Product::factory()->create(['is_active' => $active, 'is_available' => $available]);

        $response = $this->getJson('/api/v1/products')->assertOk()->assertJsonCount($visibleCount, 'data');

        $this->assertSame($active ? [$product->uuid] : [], array_column($response->json('data'), 'uuid'));
        $this->assertModelExists($product);
    }

    /**
     * @return array<string, array{bool, bool, int}>
     */
    public static function productStates(): array
    {
        return [
            'active and available' => [true, true, 1],
            'active but unavailable' => [true, false, 1],
            'hidden but available' => [false, true, 0],
            'hidden and unavailable' => [false, false, 0],
        ];
    }

    public function test_unavailable_product_remains_visible_with_message_in_list_and_details(): void
    {
        $product = Product::factory()->create(['is_available' => false, 'base_price' => '19.99']);

        $this->getJson('/api/v1/products')->assertOk()
            ->assertJsonPath('data.0.uuid', $product->uuid)
            ->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.availability_message', 'غير متوفر حاليًا');
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.availability_message', 'غير متوفر حاليًا')
            ->assertJsonPath('data.base_price', '19.99')
            ->assertJsonPath('data.category.uuid', $product->category->uuid)
            ->assertJsonPath('data.id', $product->uuid)
            ->assertJsonMissingPath('data.category_id')
            ->assertJsonPath('data.category.id', $product->category->uuid);
    }

    public function test_available_product_has_no_unavailable_message(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.availability_message', null);
    }

    public function test_hidden_product_details_return_404_but_model_remains_accessible_internally(): void
    {
        $product = Product::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/products/'.$product->uuid)->assertNotFound();

        $this->assertTrue(Product::findOrFail($product->id)->is($product));
    }

    public function test_inactive_category_hides_its_products_from_list_and_details(): void
    {
        $category = Category::factory()->inactive()->create();
        $product = Product::factory()->for($category)->create();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products/'.$product->uuid)->assertNotFound();
    }

    public function test_products_keep_sort_order_including_unavailable_items(): void
    {
        $last = Product::factory()->create(['sort_order' => 20]);
        $first = Product::factory()->create(['name' => 'A', 'sort_order' => 10, 'is_available' => false]);
        $second = Product::factory()->create(['name' => 'B', 'sort_order' => 10]);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertSame([$first->uuid, $second->uuid, $last->uuid], array_column($response->json('data'), 'uuid'));
    }

    public function test_internal_id_cannot_be_used_to_fetch_product_details(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/v1/products/'.$product->id)->assertNotFound();
    }
}
