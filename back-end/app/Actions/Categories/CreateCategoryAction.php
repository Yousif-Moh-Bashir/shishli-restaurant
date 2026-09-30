<?php

namespace App\Actions\Categories;

use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCategoryAction
{
    /**
     * @param  array{name: string, parent_uuid?: ?string, slug?: ?string, description?: ?string, image?: ?string, sort_order?: int, is_active?: bool}  $data
     */
    public function handle(array $data): Category
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($data): Category {
                    $parentUuid = $data['parent_uuid'] ?? null;
                    unset($data['parent_uuid']);
                    $parent = $parentUuid === null ? null : Category::where('uuid', $parentUuid)->lockForUpdate()->first();

                    if ($parentUuid !== null && $parent === null) {
                        throw ValidationException::withMessages(['parent_uuid' => 'القسم الرئيسي المحدد غير موجود.']);
                    }

                    $data['parent_id'] = $parent?->getKey();

                    return Category::create($data)->refresh();
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                if (filled($data['slug'] ?? null)) {
                    if (! Category::withTrashed()->where('slug', $data['slug'])->exists()) {
                        throw $exception;
                    }

                    throw ValidationException::withMessages(['slug' => 'الرابط المختصر مستخدم مسبقًا.']);
                }

                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Category creation did not complete.');
    }
}
