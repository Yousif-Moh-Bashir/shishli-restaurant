<?php

namespace Database\Factories;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BannerFactory extends Factory
{
    protected $model = Banner::class;

    public function definition(): array
    {
        return ['placement' => BannerPlacement::HomeHero, 'title' => fake()->sentence(3), 'image' => 'cms/banners/'.Str::uuid().'/'.Str::uuid().'.jpg', 'mobile_image' => null, 'is_active' => true, 'sort_order' => 0, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()];
    }
}
