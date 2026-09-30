<?php

namespace Tests\Feature\Services;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Services\PricingService;
use App\Services\ProductAvailabilityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('prices')]
    public function test_price_is_an_exact_decimal_string(?string $override, string $expected): void
    {
        $product = Product::factory()->create(['base_price' => '20.00']);
        $assignment = BranchProduct::factory()->for($product)->create(['price_override' => $override]);
        $price = app(PricingService::class)->getProductPriceForBranch($product, $assignment->branch);
        $this->assertSame($expected, $price);
        $this->assertSame('20.00', $product->fresh()->base_price);
    }

    public static function prices(): array
    {
        return ['fallback' => [null, '20.00'], 'override' => ['22.00', '22.00'],
            'cents' => ['19.99', '19.99'], 'zero' => ['0.00', '0.00'], 'maximum' => ['99999999.99', '99999999.99']];
    }

    public function test_unassigned_product_has_no_branch_price(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(PricingService::class)->getProductPriceForBranch(Product::factory()->create(), Branch::factory()->create());
    }

    public function test_price_cannot_use_another_branch_assignment(): void
    {
        $assignment = BranchProduct::factory()->create();
        $this->expectException(\InvalidArgumentException::class);
        app(PricingService::class)->getProductPriceForBranch($assignment->product, Branch::factory()->create(), $assignment);
    }

    #[DataProvider('availability')]
    public function test_availability_is_independent_of_accepting_orders(bool $active, bool $global, bool $local, bool $branchActive, bool $accepts, bool $expected): void
    {
        $product = Product::factory()->create(['is_active' => $active, 'is_available' => $global]);
        $branch = Branch::factory()->create(['is_active' => $branchActive, 'accepts_orders' => $accepts]);
        $assignment = BranchProduct::factory()->for($product)->for($branch)->create(['is_available' => $local]);
        $this->assertSame($expected, app(ProductAvailabilityService::class)->isAvailable($product, $branch, $assignment));
        $this->assertFalse(app(ProductAvailabilityService::class)->isAvailable($product, $branch, null));
    }

    public static function availability(): array
    {
        return [
            'available' => [true, true, true, true, true, true],
            'global stop' => [true, false, true, true, true, false],
            'branch stop' => [true, true, false, true, true, false],
            'inactive product' => [false, true, true, true, true, false],
            'inactive branch' => [true, true, true, false, true, false],
            'closed orders' => [true, true, true, true, false, true],
        ];
    }

    public function test_relationships_cast_the_pivot_and_soft_deletes_preserve_links(): void
    {
        $assignment = BranchProduct::factory()->withPriceOverride('19.99')->create();
        $branch = $assignment->branch;
        $product = $assignment->product;
        $this->assertInstanceOf(BranchProduct::class, $branch->products()->first()->pivot);
        $this->assertSame('19.99', $product->branches()->first()->pivot->price_override);
        $product->delete();
        $this->assertModelExists($assignment);
        $this->assertSame(0, $branch->products()->count());
        $this->assertFalse(app(ProductAvailabilityService::class)->isAvailable($product, $branch, $assignment));
        $product->forceDelete();
        $this->assertModelMissing($assignment);
        $this->assertModelExists($branch);
    }

    public function test_unique_assignment_is_enforced_in_database(): void
    {
        $assignment = BranchProduct::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        BranchProduct::factory()->create(['branch_id' => $assignment->branch_id, 'product_id' => $assignment->product_id]);
    }
}
