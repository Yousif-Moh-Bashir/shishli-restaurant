<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'attempt_number' => $this->attempt_number, 'status' => $this->status->value,
            'provider' => $this->provider, 'provider_message_id' => $this->provider_message_id,
            'error' => $this->error_code !== null ? ['code' => $this->error_code, 'message' => $this->error_message] : null,
            'manual' => (bool) ($this->metadata['manual'] ?? false),
            'started_at' => $this->started_at->toISOString(), 'finished_at' => $this->finished_at?->toISOString()];
    }
}
