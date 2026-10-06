<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingChangeLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'setting_key' => $this->setting_key, 'old_value' => $this->old_value, 'new_value' => $this->new_value,
            'changed_by' => $this->changedBy?->uuid, 'created_at' => $this->created_at->toISOString()];
    }
}
