<?php

namespace App\Services\Admin;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

class CategoryTreeService
{
    public function get(): Collection
    {
        $categories = Category::query()->withCount('products')->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
        $grouped = $categories->groupBy('parent_id');
        foreach ($categories as $category) {
            $category->setRelation('children', new Collection($grouped->get($category->id, collect())->all()));
        }

        return new Collection($categories->filter(fn (Category $c) => $c->parent_id === null)->all());
    }
}
