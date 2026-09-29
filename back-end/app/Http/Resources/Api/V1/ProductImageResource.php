<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ProductImage */
class ProductImageResource extends JsonResource
{
    /**
     * @return array{path: string, url: string, alt_text: ?string, sort_order: int, is_primary: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'path' => $this->path,
            'url' => Storage::disk('public')->url($this->path),
            'alt_text' => $this->alt_text,
            'sort_order' => $this->sort_order,
            'is_primary' => $this->is_primary,
        ];
    }
}
