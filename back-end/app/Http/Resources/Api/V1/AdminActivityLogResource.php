<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : ['id' => $this->actor->uuid, 'name' => $this->actor->name]), 'action' => $this->action, 'subject' => ['type' => $this->subject_type, 'id' => $this->subject_uuid], 'description' => $this->description, 'old_values' => $this->old_values, 'new_values' => $this->new_values, 'created_at' => $this->created_at->toISOString()];
    }
}
