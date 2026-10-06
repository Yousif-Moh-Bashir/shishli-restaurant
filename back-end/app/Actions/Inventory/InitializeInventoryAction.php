<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InitializeInventoryAction
{
    public function __construct(private InventoryService $stock) {}

    public function handle(array $data, User $actor): BranchInventory
    {
        $balance = StockQuantity::minor($data['opening_balance'] ?? '0');

        return DB::transaction(function () use ($data, $actor, $balance): BranchInventory {
            $branch = Branch::where('uuid', $data['branch_uuid'])->lockForUpdate()->firstOrFail();
            $product = Product::where('uuid', $data['product_uuid'])->lockForUpdate()->firstOrFail();
            if (! $product->tracks_inventory) {
                throw ValidationException::withMessages(['product_uuid' => 'فعّل تتبع المخزون لهذا المنتج أولًا.']);
            }
            if (BranchInventory::where('branch_id', $branch->id)->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['product_uuid' => 'مخزون هذا المنتج موجود في الفرع بالفعل.']);
            }
            $inventory = BranchInventory::create(['branch_id' => $branch->id, 'product_id' => $product->id,
                'low_stock_threshold' => isset($data['low_stock_threshold']) ? StockQuantity::decimal(StockQuantity::minor($data['low_stock_threshold'])) : null,
                'is_tracking' => true]);
            if ($balance > 0) {
                $this->stock->mutate($inventory, StockMovementType::OpeningBalance, $balance, $balance, 0, $actor, 'رصيد افتتاحي');
            }

            return $inventory;
        }, 3);
    }
}
