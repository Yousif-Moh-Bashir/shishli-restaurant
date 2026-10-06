<?php

namespace App\Http\Resources\Api\V1;

use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];
        if (isset($data['image_path'])) {
            $data['image_url'] = app(MediaService::class)->url($data['image_path'], config('filesystems.media_disk', 'public'));
            unset($data['image_path']);
        }

        return ['id' => $this->uuid, 'key' => $this->key, 'type' => $this->type->value, 'title' => $this->title, 'subtitle' => $this->subtitle, 'content' => $this->content, 'data' => $data === [] ? (object) [] : $data, 'sort_order' => $this->sort_order,
            'is_active' => $this->when($request->routeIs('api.v1.admin.*'), $this->is_active)];
    }
}
