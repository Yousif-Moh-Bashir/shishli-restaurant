<?php

namespace App\Actions\Categories;

use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCategoryAction
{
    /**
     * @param  array{name?: string, parent_uuid?: ?string, slug?: ?string, description?: ?string, image?: ?string, sort_order?: int, is_active?: bool}  $data
     */
    public function handle(Category $category, array $data): Category
    {
        try {
            return DB::transaction(function () use ($category, $data): Category {
                $category = Category::whereKey($category->getKey())->lockForUpdate()->firstOrFail();

                if (array_key_exists('parent_uuid', $data)) {
                    $parentUuid = $data['parent_uuid'];
                    unset($data['parent_uuid']);
                    $parent = $parentUuid === null ? null : Category::where('uuid', $parentUuid)->lockForUpdate()->first();

                    if ($parentUuid !== null && $parent === null) {
                        throw ValidationException::withMessages(['parent_uuid' => 'القسم الرئيسي المحدد غير موجود.']);
                    }

                    $ancestor = $parent;
                    $visited = [$category->getKey() => true];

                    while ($ancestor !== null) {
                        if (isset($visited[$ancestor->getKey()])) {
                            throw ValidationException::withMessages(['parent_uuid' => 'لا يمكن ربط القسم بنفسه أو بأحد أقسامه الفرعية.']);
                        }

                        $visited[$ancestor->getKey()] = true;
                        $ancestor = $ancestor->parent_id === null ? null : Category::withTrashed()
                            ->whereKey($ancestor->parent_id)->lockForUpdate()->first();
                    }

                    $data['parent_id'] = $parent?->getKey();
                }

                $category->update($data);

                return $category->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! Category::withTrashed()->where('slug', $data['slug'] ?? null)->whereKeyNot($category->getKey())->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages(['slug' => 'الرابط المختصر مستخدم مسبقًا.']);
        }
    }
}
