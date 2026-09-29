<?php

namespace App\Actions\Categories;

use App\Models\Category;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DeleteCategoryAction
{
    public function handle(Category $category): void
    {
        if ($category->products()->exists()) {
            throw new ConflictHttpException('The category contains products.');
        }

        $category->delete();
    }
}
