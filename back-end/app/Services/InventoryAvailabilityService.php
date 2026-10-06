<?php

namespace App\Services;

use App\Enums\InventoryIssueCode;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class InventoryAvailabilityService
{
    /** Bulk relation loading scoped to one branch; explicit null means missing, not unlimited. */
    public function load(Branch $branch, Collection $products): void
    {
        $tracked = $products->filter(fn (Product $product): bool => (bool) $product->tracks_inventory);
        $inventories = $tracked->isEmpty() ? collect() : BranchInventory::where('branch_id', $branch->id)
            ->whereIn('product_id', $tracked->pluck('id'))->get()->keyBy('product_id');
        foreach ($products as $product) {
            $product->setRelation('branchInventory', $inventories->get($product->id));
            $product->inventoryBranchId = $branch->id;
        }
    }

    public function checkProductAvailability(Branch $branch, Product $product, int $quantity): ?InventoryIssueCode
    {
        if (! $product->tracks_inventory) {
            return null;
        }
        $inventory = $product->relationLoaded('branchInventory') && $product->inventoryBranchId === $branch->id
            ? $product->getRelation('branchInventory') : BranchInventory::where('branch_id', $branch->id)->where('product_id', $product->id)->first();

        return $this->issue($inventory, $quantity * 1000);
    }

    public function issue(?BranchInventory $inventory, int $required): ?InventoryIssueCode
    {
        if ($inventory === null) {
            return InventoryIssueCode::InventoryNotConfigured;
        }
        // An existing disabled inventory is an explicit administrative bypass (request section 92).
        if (! $inventory->is_tracking) {
            return null;
        }

        return $inventory->availableMinor() <= 0 ? InventoryIssueCode::OutOfStock
            : ($inventory->availableMinor() < $required ? InventoryIssueCode::InsufficientStock : null);
    }

    public function assertAvailable(Branch $branch, Product $product, int $quantity): void
    {
        $issue = $this->checkProductAvailability($branch, $product, $quantity);
        if ($issue !== null) {
            $this->fail($issue, $product->uuid);
        }
    }

    public function fail(InventoryIssueCode $issue, ?string $productUuid = null): never
    {
        throw new HttpResponseException(ApiResponse::error($issue->message(),
            ['inventory' => [['code' => $issue->value, 'message' => $issue->message(), 'product_uuid' => $productUuid]]], 422));
    }
}
