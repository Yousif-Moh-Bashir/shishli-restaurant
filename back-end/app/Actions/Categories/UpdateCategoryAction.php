<?php

namespace App\Actions\Categories;

use App\Models\Category;

class UpdateCategoryAction
{
    /**
     * @param  array{name?: string, slug?: string, description?: ?string, image?: ?string, sort_order?: int, is_active?: bool}  $data
     */
    public function handle(Category $category, array $data): Category
    {
        $category->update($data);

        return $category->refresh();
    }
}
