<?php

namespace App\Actions\Categories;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCategoryAction
{
    public function handle(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $category = Category::whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            if ($category->children()->exists()) {
                throw ValidationException::withMessages(['category' => 'لا يمكن حذف قسم يحتوي على أقسام فرعية.']);
            }

            if ($category->products()->exists()) {
                throw ValidationException::withMessages(['category' => 'لا يمكن حذف القسم لأنه يحتوي على منتجات.']);
            }

            $category->delete();
        }, 3);
    }
}
