<?php

namespace App\Http\Resources\Api\V1;

use App\Services\MediaService;
use App\Services\RestaurantSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('api.v1.admin.*');

        return ['id' => $this->uuid, 'key' => $this->key, 'slug' => $this->slug, 'title' => $this->title,
            'seo' => ['title' => $this->meta_title ?? $this->title.' | '.app(RestaurantSettingsService::class)->getString('restaurant.name'), 'description' => $this->meta_description, 'canonical_url' => $this->canonical_url, 'og_image_url' => $this->og_image === null ? null : app(MediaService::class)->url($this->og_image, config('filesystems.media_disk', 'public'))],
            'sections' => PageSectionResource::collection($this->whenLoaded('sections')),
            'status' => $this->when($admin, $this->status->value), 'published_at' => $this->when($admin, $this->published_at?->toISOString()),
            'meta_title' => $this->when($admin, $this->meta_title), 'meta_description' => $this->when($admin, $this->meta_description), 'canonical_url' => $this->when($admin, $this->canonical_url),
            'updated_at' => $this->when($admin, $this->updated_at?->toISOString())];
    }
}
