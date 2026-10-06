<?php

namespace App\Http\Resources\Api\V1;

use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);
        $disk = config('filesystems.media_disk', 'public');
        $admin = $request->routeIs('api.v1.admin.*');

        return ['id' => $this->uuid, 'placement' => $this->placement->value, 'title' => $this->title, 'subtitle' => $this->subtitle,
            'image_url' => $media->url($this->image, $disk), 'mobile_image_url' => $media->url($this->mobile_image ?? $this->image, $disk),
            'button_text' => $this->button_text, 'button_url' => $this->button_url, 'secondary_button_text' => $this->secondary_button_text, 'secondary_button_url' => $this->secondary_button_url,
            'sort_order' => $this->sort_order, 'starts_at' => $this->when($admin, $this->starts_at?->toISOString()), 'ends_at' => $this->when($admin, $this->ends_at?->toISOString()), 'is_active' => $this->when($admin, $this->is_active)];
    }
}
