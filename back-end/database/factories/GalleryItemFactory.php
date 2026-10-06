<?php

namespace Database\Factories;

use App\Models\GalleryItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GalleryItemFactory extends Factory
{
    protected $model = GalleryItem::class;

    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'description' => fake()->sentence(), 'image' => 'cms/gallery/'.Str::uuid().'.jpg', 'alt_text' => fake()->sentence(3), 'is_active' => true, 'sort_order' => 0, 'published_at' => now()->subDay()];
    }
}
