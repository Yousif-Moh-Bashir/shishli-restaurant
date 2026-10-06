<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutboundNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $show = $request->routeIs('api.v1.admin.notifications.show');
        $recipient = $show ? $this->recipient : ($this->channel === NotificationChannel::Email ? '***@'.(str_contains($this->recipient, '@') ? substr(strrchr($this->recipient, '@'), 1) : '***') : '***'.substr($this->recipient, -4));

        return ['id' => $this->uuid, 'type' => $this->type->value, 'channel' => $this->channel->value, 'status' => $this->status->value,
            'recipient' => $recipient, 'recipient_name' => $this->recipient_name, 'subject' => $this->subject,
            'content' => $this->when($show, fn (): string => $this->content),
            'provider' => $this->provider, 'provider_message_id' => $this->provider_message_id,
            'reference' => ['type' => $this->reference_type, 'id' => $this->reference_uuid],
            'attempts' => $this->attempts, 'max_attempts' => $this->max_attempts,
            'last_error' => $this->last_error_code !== null ? ['code' => $this->last_error_code, 'message' => $this->last_error_message] : null,
            'sent_at' => $this->sent_at?->toISOString(), 'failed_at' => $this->failed_at?->toISOString(), 'created_at' => $this->created_at->toISOString()];
    }
}
