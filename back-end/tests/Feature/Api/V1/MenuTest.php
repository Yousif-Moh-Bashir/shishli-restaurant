<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidQueries')]
    public function test_menu_validates_branch_and_filters(string $query, array $fields): void
    {
        $this->getJson('/api/v1/menu'.$query)->assertUnprocessable()->assertJsonValidationErrors($fields);
    }

    public static function invalidQueries(): array
    {
        return [
            'missing branch' => ['', ['branch']],
            'invalid branch' => ['?branch=invalid', ['branch']],
            'unknown branch' => ['?branch=11111111-1111-4111-8111-111111111111', ['branch']],
            'array branch' => ['?branch[]=x', ['branch']],
            'filters' => ['?search[]=x&category[]=x&featured=yes&available=yes', ['branch', 'search', 'category', 'featured', 'available']],
        ];
    }

    public function test_inactive_and_deleted_branches_are_rejected_for_both_menu_endpoints(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);
        $product = Product::factory()->create();
        $branch->products()->attach($product);
        foreach (['/api/v1/menu', '/api/v1/menu/products/'.$product->uuid] as $url) {
            $this->getJson($url.'?branch='.$branch->uuid)->assertUnprocessable()->assertJsonValidationErrors('branch');
        }
        $branch->update(['is_active' => true]);
        $branch->delete();
        $this->getJson('/api/v1/menu?branch='.$branch->uuid)->assertUnprocessable();
    }

    public function test_menu_only_contains_explicitly_assigned_active_products_in_visible_nonempty_categories(): void
    {
        $branch = Branch::factory()->create();
        $visible = BranchProduct::factory()->for($branch)->create();
        BranchProduct::factory()->create();
        Product::factory()->create();
        Category::factory()->create();
        $inactiveProduct = Product::factory()->inactive()->create();
        $branch->products()->attach($inactiveProduct);
        $inactiveCategory = Category::factory()->inactive()->create();
        $branch->products()->attach(Product::factory()->for($inactiveCategory)->create());
        $deletedProduct = BranchProduct::factory()->for($branch)->create();
        $deletedProduct->product->delete();
        $deletedCategory = Category::factory()->create();
        $branch->products()->attach(Product::factory()->for($deletedCategory)->create());
        $deletedCategory->delete();
        $this->getJson($this->menu($branch))->assertOk()->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.branch.id', $branch->uuid)->assertJsonCount(1, 'data.categories.0.products')
            ->assertJsonPath('data.categories.0.products.0.id', $visible->product->uuid)
            ->assertJsonMissingPath('data.categories.0.products.0.product_id')
            ->assertJsonMissingPath('data.categories.0.products.0.pivot')
            ->assertJsonMissingPath('data.categories.0.products.0.images')
            ->assertJsonMissingPath('data.categories.0.products.0.option_groups');
    }

    public function test_categories_and_products_sort_by_order_then_name(): void
    {
        $branch = Branch::factory()->create();
        $lastCategory = Category::factory()->create(['sort_order' => 2, 'name' => 'A']);
        $secondCategory = Category::factory()->create(['sort_order' => 1, 'name' => 'B']);
        $firstCategory = Category::factory()->create(['sort_order' => 1, 'name' => 'A']);
        foreach ([$lastCategory, $secondCategory] as $category) {
            $branch->products()->attach(Product::factory()->for($category)->create());
        }
        $last = Product::factory()->for($firstCategory)->create(['sort_order' => 2, 'name' => 'A']);
        $second = Product::factory()->for($firstCategory)->create(['sort_order' => 1, 'name' => 'B']);
        $first = Product::factory()->for($firstCategory)->create(['sort_order' => 1, 'name' => 'A']);
        $branch->products()->attach([$last->id, $second->id, $first->id]);
        $response = $this->getJson($this->menu($branch))->assertOk();
        $this->assertSame([$firstCategory->uuid, $secondCategory->uuid, $lastCategory->uuid], array_column($response->json('data.categories'), 'id'));
        $this->assertSame([$first->uuid, $second->uuid, $last->uuid], array_column($response->json('data.categories.0.products'), 'id'));
    }

    public function test_menu_price_is_branch_specific_and_primary_image_is_loaded(): void
    {
        $product = Product::factory()->create(['base_price' => '20.00']);
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $branch->products()->attach($product, ['price_override' => '19.99']);
        $other->products()->attach($product);
        $image = ProductImage::factory()->for($product)->create(['is_primary' => true]);
        $this->getJson($this->menu($branch))->assertOk()
            ->assertJsonPath('data.categories.0.products.0.price', '19.99')
            ->assertJsonPath('data.categories.0.products.0.base_price', '20.00')
            ->assertJsonPath('data.categories.0.products.0.has_price_override', true)
            ->assertJsonPath('data.categories.0.products.0.primary_image.id', $image->uuid);
        $this->getJson($this->menu($other))->assertOk()
            ->assertJsonPath('data.categories.0.products.0.price', '20.00')
            ->assertJsonPath('data.categories.0.products.0.has_price_override', false);
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonPath('data.price', '20.00');
    }

    public function test_unavailable_products_appear_by_default_and_available_filter_uses_both_flags(): void
    {
        $branch = Branch::factory()->create(['accepts_orders' => false]);
        $category = Category::factory()->create();
        $available = Product::factory()->for($category)->create(['name' => 'A']);
        $globallyUnavailable = Product::factory()->for($category)->unavailable()->create(['name' => 'B']);
        $locallyUnavailable = Product::factory()->for($category)->create(['name' => 'C']);
        $branch->products()->attach([$available->id, $globallyUnavailable->id]);
        $branch->products()->attach($locallyUnavailable, ['is_available' => false]);
        $this->getJson($this->menu($branch))->assertOk()->assertJsonPath('data.branch.accepts_orders', false)
            ->assertJsonCount(3, 'data.categories.0.products')
            ->assertJsonPath('data.categories.0.products.0.is_available', true)
            ->assertJsonPath('data.categories.0.products.1.is_available', false)
            ->assertJsonPath('data.categories.0.products.2.is_available', false);
        $this->getJson($this->menu($branch).'&available=1')->assertOk()->assertJsonCount(1, 'data.categories.0.products')
            ->assertJsonPath('data.categories.0.products.0.id', $available->uuid);
        $this->getJson($this->menu($branch).'&available=0')->assertOk()->assertJsonCount(2, 'data.categories.0.products');
    }

    public function test_search_category_and_featured_filters_combine_and_false_is_supported(): void
    {
        $branch = Branch::factory()->create();
        $category = Category::factory()->create();
        $target = Product::factory()->for($category)->featured()->create(['name' => 'شيش طاووق', 'sku' => 'SH-001']);
        $notFeatured = Product::factory()->for($category)->create(['name' => 'شيش']);
        $otherCategory = Product::factory()->featured()->create(['name' => 'شيش']);
        $branch->products()->attach([$target->id, $notFeatured->id, $otherCategory->id]);
        foreach ([$category->slug, $category->uuid] as $key) {
            $this->getJson($this->menu($branch).'&'.http_build_query(['category' => $key, 'search' => 'شيش', 'featured' => 1]))
                ->assertOk()->assertJsonCount(1, 'data.categories')->assertJsonCount(1, 'data.categories.0.products')
                ->assertJsonPath('data.categories.0.products.0.id', $target->uuid);
        }
        $this->getJson($this->menu($branch).'&search=SH-001')->assertOk()->assertJsonCount(1, 'data.categories');
        $this->getJson($this->menu($branch).'&featured=0')->assertOk()->assertJsonCount(1, 'data.categories.0.products')
            ->assertJsonPath('data.categories.0.products.0.id', $notFeatured->uuid);
        $this->getJson($this->menu($branch).'&featured=&available=')->assertOk()->assertJsonCount(2, 'data.categories');
        $this->getJson($this->menu($branch).'&category=unknown')->assertOk()->assertJsonPath('data.categories', []);
        $this->getJson($this->menu($branch).'&'.http_build_query(['search' => "' OR 1=1 --"]))
            ->assertOk()->assertJsonPath('data.categories', []);
    }

    #[DataProvider('hiddenDetails')]
    public function test_hidden_or_unassigned_products_return_404(string $state): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        if ($state !== 'unassigned') {
            $branch->products()->attach($product);
        }
        match ($state) {
            'inactive product' => $product->update(['is_active' => false]),
            'deleted product' => $product->delete(),
            'inactive category' => $product->category->update(['is_active' => false]),
            'deleted category' => $product->category->delete(),
            default => null,
        };
        $this->getJson('/api/v1/menu/products/'.$product->uuid.'?branch='.$branch->uuid)->assertNotFound();
    }

    public static function hiddenDetails(): array
    {
        return [['unassigned'], ['inactive product'], ['deleted product'], ['inactive category'], ['deleted category']];
    }

    public function test_detail_includes_branch_price_gallery_and_active_options_with_effective_overrides(): void
    {
        $assignment = BranchProduct::factory()->withPriceOverride('22.00')->unavailable()->create();
        $product = $assignment->product;
        $image = ProductImage::factory()->for($product)->create(['is_primary' => true]);
        ProductImage::factory()->for($product)->create();
        $group = OptionGroup::factory()->create();
        $hidden = OptionGroup::factory()->inactive()->create();
        $product->optionGroups()->attach($group, ['is_required_override' => true, 'min_select_override' => 1]);
        $product->optionGroups()->attach($hidden);
        $large = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '10.00', 'sort_order' => 2]);
        $small = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '0.00', 'sort_order' => 1]);
        OptionValue::factory()->for($group, 'optionGroup')->inactive()->create();
        $this->getJson('/api/v1/menu/products/'.$product->uuid.'?branch='.$assignment->branch->uuid.'&available=1')
            ->assertOk()->assertJsonPath('data.id', $product->uuid)->assertJsonPath('data.price', '22.00')
            ->assertJsonPath('data.is_available', false)->assertJsonPath('data.available', false)
            ->assertJsonPath('data.primary_image.id', $image->uuid)->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.category.id', $product->category->uuid)->assertJsonCount(1, 'data.option_groups')
            ->assertJsonPath('data.option_groups.0.id', $group->uuid)->assertJsonPath('data.option_groups.0.is_required', true)
            ->assertJsonPath('data.option_groups.0.min_select', 1)->assertJsonPath('data.option_groups.0.max_select', 1)
            ->assertJsonCount(2, 'data.option_groups.0.values')->assertJsonPath('data.option_groups.0.values.0.id', $small->uuid)
            ->assertJsonPath('data.option_groups.0.values.1.id', $large->uuid)
            ->assertJsonPath('data.option_groups.0.values.0.price_modifier', '0.00')
            ->assertJsonPath('data.option_groups.0.values.1.price_modifier', '10.00')
            ->assertJsonMissingPath('data.option_groups.0.overrides')
            ->assertJsonMissingPath('data.option_groups.0.values.0.option_group_id');
        $this->getJson('/api/v1/menu/products/'.$product->uuid)->assertUnprocessable()->assertJsonValidationErrors('branch');
        $this->getJson('/api/v1/menu/products/'.Str::uuid().'?branch='.$assignment->branch->uuid)->assertNotFound();
    }

    public function test_empty_branch_returns_empty_menu_even_when_products_exist_elsewhere(): void
    {
        $branch = Branch::factory()->create();
        BranchProduct::factory()->create();
        $this->getJson($this->menu($branch))->assertOk()->assertJsonPath('data.categories', []);
    }

    public function test_menu_and_detail_query_counts_do_not_grow_per_category_product_or_option(): void
    {
        $branch = Branch::factory()->create();
        $assignment = BranchProduct::factory()->for($branch)->create();
        DB::enableQueryLog();
        $this->getJson($this->menu($branch))->assertOk();
        $baseline = count(DB::getQueryLog());
        BranchProduct::factory()->count(8)->for($branch)->create();
        DB::flushQueryLog();
        $this->getJson($this->menu($branch))->assertOk()->assertJsonCount(9, 'data.categories');
        $this->assertSame($baseline, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $group = OptionGroup::factory()->create();
        OptionValue::factory()->for($group, 'optionGroup')->create();
        $assignment->product->optionGroups()->attach($group);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $url = '/api/v1/menu/products/'.$assignment->product->uuid.'?branch='.$branch->uuid;
        $this->getJson($url)->assertOk();
        $detailBaseline = count(DB::getQueryLog());
        $groups = OptionGroup::factory()->count(5)->create();
        foreach ($groups as $additional) {
            OptionValue::factory()->count(3)->for($additional, 'optionGroup')->create();
        }
        $assignment->product->optionGroups()->attach($groups->modelKeys());
        DB::flushQueryLog();
        $this->getJson($url)->assertOk()->assertJsonCount(6, 'data.option_groups');
        $this->assertSame($detailBaseline, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    private function menu(Branch $branch): string
    {
        return '/api/v1/menu?branch='.$branch->uuid;
    }
}
