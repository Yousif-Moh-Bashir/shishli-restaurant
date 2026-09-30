<?php

namespace Tests\Feature\Models;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OptionGroupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_shared_groups_and_values_survive_product_deletion(): void
    {
        $group = OptionGroup::factory()->create();
        $value = OptionValue::factory()->for($group, 'optionGroup')->create(['price_modifier' => '-2.25']);
        $products = Product::factory()->count(2)->create();
        foreach ($products as $product) {
            $product->optionGroups()->attach($group);
        }
        $products[0]->delete();
        $this->assertModelExists($group);
        $this->assertSame('-2.25', $value->fresh()->price_modifier);
        $this->assertSame([$products[1]->id], $group->products()->pluck('products.id')->all());
        $group->forceDelete();
        $this->assertModelMissing($value);
        $this->assertDatabaseCount('product_option_groups', 0);
        $this->assertModelExists($products[1]);
    }

    public function test_product_cannot_attach_same_group_twice(): void
    {
        $product = Product::factory()->create();
        $group = OptionGroup::factory()->create();
        $product->optionGroups()->attach($group);
        $this->expectException(QueryException::class);
        $product->optionGroups()->attach($group);
    }
}
