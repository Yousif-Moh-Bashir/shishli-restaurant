<?php

namespace App\Http\Resources\Api\V1;

use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GalleryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('api.v1.admin.*');

        return ['id' => $this->uuid, 'title' => $this->title, 'description' => $this->description, 'image_url' => app(MediaService::class)->url($this->image, config('filesystems.media_disk', 'public')), 'alt_text' => $this->alt_text, 'sort_order' => $this->sort_order, 'is_active' => $this->when($admin, $this->is_active), 'published_at' => $this->when($admin, $this->published_at?->toISOString())];
    }
}
