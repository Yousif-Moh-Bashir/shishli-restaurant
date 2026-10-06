<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkProductUpdateAction
{
    public function __construct(private UpdateProductAction $update, private AdminActivityService $audit) {}

    public function handle(User $actor, array $uuids, string $field, bool $value): int
    {
        abort_unless($actor->can('products.update'), 403);
        abort_unless(in_array($field, ['is_active', 'is_available'], true), 422);

        return DB::transaction(function () use ($actor, $uuids, $field, $value): int {
            $products = Product::query()->whereIn('uuid', $uuids)->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== count($uuids)) {
                throw ValidationException::withMessages(['product_uuids' => 'One or more products are unavailable.']);
            }
            foreach ($products as $product) {
                $this->update->handle($product, [$field => $value]);
            }
            $this->audit->record($actor, 'products.bulk.'.$field, 'product', null, [], ['product_uuids' => $uuids, $field => $value]);

            return $products->count();
        }, 3);
    }
}
