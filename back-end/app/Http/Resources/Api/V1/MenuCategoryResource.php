<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid, 'name' => $this->name, 'slug' => $this->slug, 'image' => $this->image,
            'products' => MenuProductResource::collection($this->whenLoaded('menuProducts')),
        ];
    }
}
