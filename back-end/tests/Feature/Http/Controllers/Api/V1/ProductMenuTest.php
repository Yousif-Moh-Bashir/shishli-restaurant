<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Branch;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductMenuTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_details_include_only_assigned_active_groups_and_values_in_order(): void
    {
        $product = Product::factory()->create(['slug' => 'shish-tawook', 'base_price' => '20.00', 'is_available' => false]);
        $last = OptionGroup::factory()->create(['sort_order' => 20]);
        $first = OptionGroup::factory()->create(['name' => 'الإضافات', 'type' => 'multiple', 'sort_order' => 1, 'max_select' => 3]);
        $hidden = OptionGroup::factory()->create(['is_active' => false]);
        OptionGroup::factory()->create();
        $product->optionGroups()->attach([
            $last->id => ['sort_order' => 20], $first->id => ['sort_order' => 1], $hidden->id => ['sort_order' => 0],
        ]);
        $second = OptionValue::factory()->for($first, 'optionGroup')->create(['sort_order' => 10]);
        $value = OptionValue::factory()->for($first, 'optionGroup')->create(['name' => 'حمص', 'price_modifier' => '5.25', 'sort_order' => 0]);
        OptionValue::factory()->for($first, 'optionGroup')->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('data.id', $product->uuid)
            ->assertJsonPath('data.price', '20.00')->assertJsonPath('data.available', false)
            ->assertJsonPath('data.category.name', $product->category->name)
            ->assertJsonPath('data.images', [])->assertJsonCount(2, 'data.option_groups')
            ->assertJsonPath('data.option_groups.0.name', 'الإضافات')
            ->assertJsonPath('data.option_groups.0.type', 'multiple')
            ->assertJsonPath('data.option_groups.0.required', false)
            ->assertJsonPath('data.option_groups.0.max_select', 3)
            ->assertJsonPath('data.option_groups.0.options.0.price', '5.25');
        $this->assertSame([$value->uuid, $second->uuid], array_column($response->json('data.option_groups.0.options'), 'id'));
        $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
    }

    public function test_details_without_options_return_empty_array_and_unknown_slug_returns_json_404(): void
    {
        $product = Product::factory()->create();
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonPath('data.option_groups', []);
        $this->getJson('/api/v1/products/missing')->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_combined_filters_paginate_and_preserve_query_parameters(): void
    {
        $category = Category::factory()->create(['slug' => 'shish']);
        $products = Product::factory()->count(3)->for($category)->create(['name' => 'دجاج مشوي', 'is_featured' => true]);
        Product::factory()->for($category)->create(['name' => 'دجاج', 'is_featured' => false]);
        Product::factory()->create(['name' => 'دجاج', 'is_featured' => true]);
        Product::factory()->for($category)->create(['name' => 'لحم', 'is_featured' => true]);
        Product::factory()->for($category)->create(['name' => 'دجاج', 'is_featured' => true, 'is_active' => false]);

        $response = $this->getJson('/api/v1/products?'.http_build_query(['category' => 'shish', 'search' => 'دجاج', 'featured' => 1, 'page' => 2, 'per_page' => 2]))
            ->assertOk()->assertJsonPath('success', true)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $products[2]->uuid)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
        $this->assertStringContainsString('category=shish', $response->json('links.prev'));
        $this->getJson('/api/v1/products?featured=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?category=missing')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pagination_defaults_and_validation(): void
    {
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/v1/products?page=0&per_page=101&featured=yes&search[]=x&category[]=x&branch[]=x')
            ->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page', 'featured', 'search', 'category', 'branch']);
    }

    public function test_active_branch_accepts_uuid_or_slug_for_shared_menu(): void
    {
        $branch = Branch::factory()->create();
        Product::factory()->create();
        foreach ([$branch->uuid, $branch->slug] as $key) {
            $this->getJson('/api/v1/products?branch='.$key)->assertOk()->assertJsonCount(1, 'data');
        }
        $branch->update(['is_active' => false]);
        $this->getJson('/api/v1/products?branch='.$branch->uuid)->assertNotFound();
        $this->getJson('/api/v1/products?branch=missing')->assertNotFound();
    }

    public function test_category_counts_visible_products_including_unavailable(): void
    {
        $category = Category::factory()->create();
        Product::factory()->for($category)->create();
        Product::factory()->for($category)->create(['is_available' => false]);
        Product::factory()->for($category)->create(['is_active' => false]);
        $this->getJson('/api/v1/categories')->assertOk()
            ->assertJsonPath('data.0.id', $category->uuid)->assertJsonPath('data.0.products_count', 2);
    }
}
