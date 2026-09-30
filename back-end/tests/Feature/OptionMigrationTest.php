<?php

namespace Tests\Feature;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_checks_both_tables_before_modifying_either_schema(): void
    {
        $migration = require database_path('migrations/2026_09_29_215059_complete_product_options_schema.php');
        $migration->down();
        $groupId = DB::table('option_groups')->insertGetId(['name' => 'Size', 'type' => 'single']);
        $valueId = DB::table('option_values')->insertGetId(['option_group_id' => $groupId, 'name' => str_repeat('x', 151)]);
        try {
            try {
                $migration->up();
                $this->fail('Oversized legacy names must not be truncated.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('option_values', $exception->getMessage());
                $this->assertFalse(Schema::hasColumn('option_groups', 'uuid'));
                $this->assertFalse(Schema::hasColumn('option_values', 'uuid'));
                $this->assertSame(151, strlen(DB::table('option_values')->where('id', $valueId)->value('name')));
            }
        } finally {
            DB::table('option_values')->where('id', $valueId)->update(['name' => 'Large']);
            $migration->up();
        }
    }

    public function test_upgrade_preserves_legacy_groups_prices_and_links(): void
    {
        $product = Product::factory()->create();
        $migration = require database_path('migrations/2026_09_29_215059_complete_product_options_schema.php');
        $migration->down();
        $groupId = DB::table('option_groups')->insertGetId([
            'name' => 'Size', 'type' => 'single', 'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'sort_order' => 7,
        ]);
        $valueId = DB::table('option_values')->insertGetId([
            'option_group_id' => $groupId, 'name' => 'Large', 'price_modifier' => '10.25',
        ]);
        DB::table('product_option_groups')->insert(['product_id' => $product->id, 'option_group_id' => $groupId]);
        $migration->up();
        $group = OptionGroup::findOrFail($groupId);
        $value = OptionValue::findOrFail($valueId);
        $this->assertTrue(Str::isUuid($group->uuid));
        $this->assertTrue(Str::isUuid($value->uuid));
        $this->assertNotEmpty($group->slug);
        $this->assertNotEmpty($value->slug);
        $this->assertNotNull($group->created_at);
        $this->assertNotNull($value->created_at);
        $this->assertSame('10.25', $value->price_modifier);
        $link = $product->optionGroups()->firstOrFail();
        $this->assertSame($groupId, $link->id);
        $this->assertSame(7, (int) $link->pivot->sort_order);
        $this->assertNull($link->pivot->min_select_override);
        $this->assertNotNull($link->pivot->created_at);
    }
}
