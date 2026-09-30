<?php

namespace App\Actions\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): Product
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($data): Product {
                    $category = Category::where('uuid', $data['category_uuid'])->lockForUpdate()->first();

                    if ($category === null) {
                        throw ValidationException::withMessages(['category_uuid' => 'القسم المحدد غير موجود.']);
                    }

                    unset($data['category_uuid']);
                    $data['category_id'] = $category->getKey();

                    return Product::create($data)->refresh();
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                foreach (['slug' => 'الرابط المختصر مستخدم مسبقًا.', 'sku' => 'رمز المنتج مستخدم مسبقًا.'] as $field => $message) {
                    if (filled($data[$field] ?? null) && Product::withTrashed()->where($field, $data[$field])->exists()) {
                        throw ValidationException::withMessages([$field => $message]);
                    }
                }

                if (filled($data['slug'] ?? null) || $attempt >= 4) {
                    throw $exception;
                }
            }
        }
    }
}
