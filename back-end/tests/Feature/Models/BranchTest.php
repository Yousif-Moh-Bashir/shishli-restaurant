<?php

namespace Tests\Feature\Models;

use App\Models\Branch;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_branch_has_internal_id_and_public_uuid_for_route_binding(): void
    {
        $first = Branch::factory()->create();
        $second = Branch::factory()->create();

        $this->assertIsInt($first->id);
        $this->assertGreaterThan($first->id, $second->id);
        $this->assertTrue(Str::isUuid($first->uuid));
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertSame($first->id, (new Branch)->resolveRouteBinding($first->uuid)->id);
        $this->assertArrayNotHasKey('id', $first->toArray());
    }

    #[DataProvider('coordinates')]
    public function test_coordinates_preserve_seven_decimal_places(string $latitude, string $longitude): void
    {
        $branch = Branch::factory()->create(['latitude' => $latitude, 'longitude' => $longitude]);

        $branch->refresh();

        $this->assertSame($latitude, $branch->latitude);
        $this->assertSame($longitude, $branch->longitude);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function coordinates(): array
    {
        return [
            'fractional coordinates' => ['24.7135517', '46.6752957'],
            'negative and three-digit coordinates' => ['-89.1234567', '-179.7654321'],
            'zero coordinates' => ['0.0000000', '0.0000000'],
        ];
    }

    public function test_optional_contacts_and_coordinates_default_to_null_and_flags_to_true(): void
    {
        $branch = Branch::create(['name' => 'Default branch', 'slug' => 'default-branch'])->refresh();

        $this->assertNull($branch->whatsapp);
        $this->assertNull($branch->latitude);
        $this->assertNull($branch->longitude);
        $this->assertTrue($branch->is_active);
        $this->assertTrue($branch->accepts_orders);
    }

    public function test_branch_can_be_disabled_and_stop_accepting_orders(): void
    {
        $branch = Branch::factory()->create();
        $uuid = $branch->uuid;

        $branch->update(['is_active' => false, 'accepts_orders' => false]);
        $branch->refresh();

        $this->assertFalse($branch->is_active);
        $this->assertFalse($branch->accepts_orders);
        $this->assertSame($uuid, $branch->uuid);
    }

    #[DataProvider('uniqueFields')]
    public function test_duplicate_public_identifiers_are_rejected(string $field): void
    {
        $branch = Branch::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Branch::factory()->create([$field => $branch->{$field}]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uniqueFields(): array
    {
        return ['uuid' => ['uuid'], 'slug' => ['slug']];
    }

    public function test_example_seeder_preserves_arabic_and_leading_zero_without_duplicates(): void
    {
        $this->seed(BranchSeeder::class);
        $branch = Branch::where('slug', 'al-masif')->sole();
        $uuid = $branch->uuid;

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseCount('branches', 1);
        $this->assertSame('فرع المصيف', $branch->name);
        $this->assertSame('0551040122', $branch->phone);
        $this->assertSame('0551040122', $branch->whatsapp);
        $this->assertSame('الرياض', $branch->city);
        $this->assertSame('المصيف', $branch->district);
        $this->assertTrue($branch->is_active);
        $this->assertTrue($branch->accepts_orders);
        $this->assertSame(1, $branch->sort_order);
        $this->assertSame('المصيف - بجوار إشارة المصيف', $branch->address);
        $this->assertSame($uuid, $branch->fresh()->uuid);
        $this->assertNull($branch->latitude);
        $this->assertNull($branch->longitude);
    }

    public function test_whatsapp_preserves_leading_zero(): void
    {
        $branch = Branch::factory()->create(['whatsapp' => '0551040122']);

        $this->assertSame('0551040122', $branch->fresh()->whatsapp);
    }
}
