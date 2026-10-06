<?php

namespace Database\Factories;

use App\Enums\PageSectionType;
use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Database\Eloquent\Factories\Factory;

class PageSectionFactory extends Factory
{
    protected $model = PageSection::class;

    public function definition(): array
    {
        return ['page_id' => Page::factory()->state(['key' => 'home', 'slug' => 'home']), 'key' => 'hero', 'type' => PageSectionType::Hero, 'title' => fake()->sentence(3), 'sort_order' => 0, 'is_active' => true, 'data' => []];
    }
}
