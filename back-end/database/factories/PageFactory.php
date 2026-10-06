<?php

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        return ['key' => fake()->unique()->slug(2), 'title' => fake()->sentence(3), 'slug' => fake()->unique()->slug(3), 'status' => PageStatus::Published, 'published_at' => now()->subDay()];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => PageStatus::Draft]);
    }
}
