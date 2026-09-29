<?php

namespace App\Actions\Categories;

use App\Models\Category;

class CreateCategoryAction
{
    /**
     * @param  array{name: string, parent_uuid?: ?string, slug?: ?string, description?: ?string, image?: ?string, sort_order?: int, is_active?: bool}  $data
     */
    public function handle(array $data): Category
    {
        $parentUuid = $data['parent_uuid'] ?? null;
        unset($data['parent_uuid']);

        $data['parent_id'] = $parentUuid !== null
            ? Category::where('uuid', $parentUuid)->firstOrFail()->getKey()
            : null;

        return Category::create($data)->refresh();
    }
}
